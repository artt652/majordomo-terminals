<?php
/*
 * Очередь сообщений терминалов (таблицы terminals_queue и terminals_state).
 *
 * Работает только для терминалов, дополнение TTS которых объявляет useQueue() и возвращает true.
 * Пока терминал произносит одно сообщение, следующие ждут; очередь разбирает отложенное
 * задание (setTimeOut → cycle_scheduler), отдельного цикла нет.
 * - срочные (ask и уровень >= terminals::QUEUE_URGENT_LEVEL) не ждут обычных: прерывают обычное
 *   сообщение (оно не повторяется), а если звучит другое срочное — ждут его конца, но идут
 *   раньше всех обычных; обычные ждут, пока звучат срочные;
 * - повтор: та же фраза, что сейчас звучит или уже ждёт, второй раз не ставится; готовый файл
 *   голоса этой фразы (SAY_CACHED_READY) звучит сразу, если её текст уже на экране,
 *   или присоединяется к ожидающей записи;
 * - терминал занят, только если дополнение сообщило, что проиграло сообщение (вернуло истину);
 * - если у дополнения есть saveState()/restoreState(), перед первым сообщением серии состояние
 *   плеера сохраняется, а после последнего восстанавливается;
 * - сообщения старше terminals::QUEUE_TTL секунд не проигрываются; сторож (MINUTELY) запускает
 *   застрявшую очередь, startup сбрасывает «занято».
 */

// Дополнение TTS терминала (или null)
function terminalsQueueAddon($terminal_rec)
{
    if (empty($terminal_rec['TTS_TYPE'])) {
        return null;
    }
    include_once DIR_MODULES . 'terminals/tts_addon.class.php';
    $addon_file = DIR_MODULES . 'terminals/tts/' . $terminal_rec['TTS_TYPE'] . '.addon.php';
    if (!file_exists($addon_file)) {
        DebMes("Could not find $addon_file", 'terminals');
        return null;
    }
    include_once($addon_file);
    return new $terminal_rec['TTS_TYPE']($terminal_rec);
}

// Просит ли дополнение очередь
function terminalsQueueUsed($tts)
{
    return is_object($tts) && method_exists($tts, 'useQueue') && $tts->useQueue();
}

function terminalsQueueAdd($terminal_rec, $kind, $message, $level, $file)
{
    $tid = isset($terminal_rec['ID']) ? (int)$terminal_rec['ID'] : 0;
    $urgent = ($kind == 'ask' || (int)$level >= terminals::QUEUE_URGENT_LEVEL);
    if (!$tid || !terminalsQueueLock($tid)) {
        // без ID или очередь занята другим процессом слишком долго — не теряем сообщение
        $played = terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file);
        return $played[0];
    }
    $now = time();
    $result = true;
    // текст хранится в JSON (\uXXXX): таблица в utf8, эмодзи иначе превратятся в «?»
    $key = json_encode((string)$message);
    $current = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 ORDER BY STARTED DESC, ID DESC LIMIT 1");
    $busy = !empty($current['ID']) && (int)$current['FINISH'] > $now;

    if ($busy && $current['MESSAGE'] === $key) {
        if ($kind == 'cached' && $current['FILENAME'] == '') {
            // голос фразы, текст которой уже показан: звучит сразу, терминал занят дольше
            $played = terminalsQueuePlay($terminal_rec, 'cached', $message, $level, $file);
            $current['FILENAME'] = $file;
            $current['FINISH'] = max((int)$current['FINISH'], $now + $played[1]);
            SQLUpdate('terminals_queue', $current);
            terminalsQueueSchedule($tid, (int)$current['FINISH'] - $now);
            $result = $played[0];
        }
        terminalsQueueUnlock($tid);
        return $result;
    }

    $waiting = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED=0 AND MESSAGE='" . DBSafe($key) . "' ORDER BY ID LIMIT 1");
    if (!empty($waiting['ID']) && $urgent && (int)$waiting['LEVEL'] < terminals::QUEUE_URGENT_LEVEL) {
        // та же фраза ждёт как обычная, а теперь пришла срочной — ставим заново как срочную
        if ($file == '') {
            $file = $waiting['FILENAME'];
        }
        SQLExec("DELETE FROM terminals_queue WHERE ID=" . (int)$waiting['ID']);
        $waiting = array();
    }
    if (!empty($waiting['ID'])) {
        // та же фраза уже ждёт: присоединяем к ней файл голоса, повтор не ставим
        if ($file != '' && $waiting['FILENAME'] == '') {
            $waiting['FILENAME'] = $file;
            SQLUpdate('terminals_queue', $waiting);
        }
        terminalsQueueUnlock($tid);
        return true;
    }

    $rec = array(
        'TERMINAL_ID' => $tid,
        'KIND' => $kind,
        'MESSAGE' => $key,
        'LEVEL' => (int)$level,
        'FILENAME' => (string)$file,
        'ADDED' => $now,
        'STARTED' => 0,
        'FINISH' => 0,
    );
    $hasWaiting = SQLSelectOne("SELECT ID FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED=0 LIMIT 1");
    $currentUrgent = $busy && (int)$current['LEVEL'] >= terminals::QUEUE_URGENT_LEVEL;
    if ((!$busy && empty($hasWaiting['ID'])) || ($urgent && !$currentUrgent)) {
        // терминал свободен, или срочное прерывает обычное (прерванное не повторяется): сразу
        if (!$busy) {
            terminalsQueueSaveState($terminal_rec);
        }
        SQLExec("DELETE FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0");
        $rec['STARTED'] = $now;
        $played = terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file);
        $rec['FINISH'] = $now + $played[1];
        SQLInsert('terminals_queue', $rec);
        $result = $played[0];
        terminalsQueueScheduleAfter($tid, $played[1]);
    } else {
        // ждём своей очереди
        SQLInsert('terminals_queue', $rec);
        terminalsQueueSchedule($tid, $busy ? (int)$current['FINISH'] - $now : 0);
        DebMes('Queued for ' . $terminal_rec['TITLE'] . ': ' . $message, 'terminals');
    }
    terminalsQueueUnlock($tid);
    return $result;
}

// Следующее сообщение очереди терминала (вызывается отложенным заданием)
function terminalsQueueNext($tid)
{
    $tid = (int)$tid;
    if (!$tid || !terminalsQueueLock($tid)) {
        return;
    }
    $now = time();
    $current = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 ORDER BY STARTED DESC, ID DESC LIMIT 1");
    if (!empty($current['ID']) && (int)$current['FINISH'] > $now) {
        // текущее продлили (пришёл голос) — ждём его конца
        terminalsQueueSchedule($tid, (int)$current['FINISH'] - $now);
        terminalsQueueUnlock($tid);
        return;
    }
    $terminal_rec = getTerminalByID($tid);
    if (empty($terminal_rec['ID'])) {
        SQLExec("DELETE FROM terminals_queue WHERE TERMINAL_ID=$tid");
        SQLExec("DELETE FROM terminals_state WHERE TERMINAL_ID=$tid");
        terminalsQueueUnlock($tid);
        return;
    }
    SQLExec("DELETE FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0");
    terminalsQueueExpire($tid);
    if (empty($current['ID'])) {
        // серия начинается заново (после сброса или сторожа)
        terminalsQueueSaveState($terminal_rec);
    }
    // сначала срочные, затем обычные, в порядке поступления
    while ($next = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED=0 ORDER BY (LEVEL>=" . (int)terminals::QUEUE_URGENT_LEVEL . ") DESC, ID LIMIT 1")) {
        if (empty($next['ID'])) {
            break;
        }
        $now = time();
        $message = json_decode($next['MESSAGE'], true);
        $played = terminalsQueuePlay($terminal_rec, $next['KIND'], is_string($message) ? $message : '', (int)$next['LEVEL'], $next['FILENAME']);
        $next['STARTED'] = $now;
        $next['FINISH'] = $now + $played[1];
        SQLUpdate('terminals_queue', $next);
        if ($played[1] > 0) {
            terminalsQueueScheduleAfter($tid, $played[1]);
            terminalsQueueUnlock($tid);
            return;
        }
        // дополнение ничего не проиграло — терминал свободен, сразу следующее
        SQLExec("DELETE FROM terminals_queue WHERE ID=" . (int)$next['ID']);
    }
    // очередь пуста — вернуть то, что играло до сообщений
    terminalsQueueRestoreState($terminal_rec);
    terminalsQueueUnlock($tid);
}

// Отдать сообщение дополнению TTS. Возвращает [результат дополнения, сколько секунд терминал занят]
function terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file)
{
    $result = false;
    $busy = 0;
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts)) {
        return array($result, $busy);
    }
    if ($kind == 'say' || $kind == 'ask') {
        DebMes("Saying to " . $terminal_rec['TITLE'] . ' (level ' . $level . '): ' . $message, 'terminals');
        if ($kind == 'ask') {
            $result = $tts->ask($message, 9999);
        } else {
            $result = $tts->say($message, $level);
        }
        if ($result) {
            $busy = terminalsQueueSpeechSeconds($message);
        }
    }
    if ($file != '') {
        DebMes("Saying cached to " . $terminal_rec['TITLE'] . ' (level ' . $level . '): ' . $message . " (file: $file)", 'terminals');
        $cached = $tts->sayCached($message, $level, $file);
        if ($cached) {
            $seconds = 0;
            if (is_file($file) && function_exists('getMediaDurationSeconds')) {
                $seconds = (int)getMediaDurationSeconds($file);
            }
            $busy = max($busy, ($seconds > 0) ? $seconds + 1 : terminalsQueueSpeechSeconds($message));
        }
        if ($kind == 'cached') {
            $result = $cached;
        }
    }
    return array($result, $busy);
}

// Примерная длительность речи: ~12 символов в секунду + 1 с
function terminalsQueueSpeechSeconds($message)
{
    $message = (string)$message;
    $len = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    return (int)ceil($len / 12) + 1;
}

function terminalsQueueSchedule($tid, $seconds)
{
    setTimeOut('terminals_queue_' . (int)$tid,
        "include_once(DIR_MODULES . 'terminals/terminals.class.php'); terminalsQueueNext(" . (int)$tid . ");",
        max(0, (int)$seconds));
}

// После текущего сообщения: следующее из очереди или восстановление плеера
function terminalsQueueScheduleAfter($tid, $seconds)
{
    $tid = (int)$tid;
    $more = SQLSelectOne("SELECT ID FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED=0 LIMIT 1");
    $state = SQLSelectOne("SELECT ID FROM terminals_state WHERE TERMINAL_ID=$tid LIMIT 1");
    if (!empty($more['ID']) || !empty($state['ID'])) {
        terminalsQueueSchedule($tid, $seconds);
    }
}

// Удалить ожидающие сообщения старше срока жизни
function terminalsQueueExpire($tid = 0)
{
    $where = "STARTED=0 AND ADDED<" . (time() - terminals::QUEUE_TTL);
    if ($tid) {
        $where .= " AND TERMINAL_ID=" . (int)$tid;
    }
    $old = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM terminals_queue WHERE $where");
    if (!empty($old['TOTAL'])) {
        SQLExec("DELETE FROM terminals_queue WHERE $where");
        DebMes('Expired queued messages: ' . (int)$old['TOTAL'], 'terminals');
    }
}

// Сохранить состояние плеера перед первым сообщением серии
function terminalsQueueSaveState($terminal_rec)
{
    $tid = (int)$terminal_rec['ID'];
    $saved = SQLSelectOne("SELECT ID FROM terminals_state WHERE TERMINAL_ID=$tid LIMIT 1");
    if (!empty($saved['ID'])) {
        return;
    }
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts) || !method_exists($tts, 'saveState')) {
        return;
    }
    $state = $tts->saveState();
    if (empty($state)) {
        return;
    }
    $rec = array(
        'TERMINAL_ID' => $tid,
        'PLAYER_STATE' => json_encode($state),
        'SAVED' => time(),
    );
    SQLInsert('terminals_state', $rec);
    DebMes('Player state saved for ' . $terminal_rec['TITLE'] . ': ' . $rec['PLAYER_STATE'], 'terminals');
}

// Вернуть состояние плеера после последнего сообщения серии
function terminalsQueueRestoreState($terminal_rec)
{
    $tid = (int)$terminal_rec['ID'];
    $saved = SQLSelectOne("SELECT * FROM terminals_state WHERE TERMINAL_ID=$tid LIMIT 1");
    if (empty($saved['ID'])) {
        return;
    }
    SQLExec("DELETE FROM terminals_state WHERE TERMINAL_ID=$tid");
    $state = json_decode($saved['PLAYER_STATE'], true);
    $tts = terminalsQueueAddon($terminal_rec);
    if (empty($state) || !is_object($tts) || !method_exists($tts, 'restoreState')) {
        return;
    }
    DebMes('Restoring player state for ' . $terminal_rec['TITLE'] . ': ' . $saved['PLAYER_STATE'], 'terminals');
    $tts->restoreState($state);
}

// Сторож (MINUTELY): просроченные сообщения и застрявшая очередь
function terminalsQueueWatchdog()
{
    terminalsQueueExpire();
    $now = time();
    $ids = SQLSelect("SELECT DISTINCT TERMINAL_ID FROM terminals_queue UNION SELECT DISTINCT TERMINAL_ID FROM terminals_state");
    foreach ($ids as $row) {
        $tid = (int)$row['TERMINAL_ID'];
        $job = SQLSelectOne("SELECT ID FROM jobs WHERE TITLE='terminals_queue_$tid' AND PROCESSED=0 LIMIT 1");
        if (!empty($job['ID'])) {
            continue;
        }
        $current = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 ORDER BY STARTED DESC, ID DESC LIMIT 1");
        if (!empty($current['ID']) && (int)$current['FINISH'] + terminals::QUEUE_WATCHDOG > $now) {
            continue;
        }
        $waiting = SQLSelectOne("SELECT ID FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED=0 LIMIT 1");
        $state = SQLSelectOne("SELECT ID FROM terminals_state WHERE TERMINAL_ID=$tid LIMIT 1");
        if (!empty($waiting['ID']) || !empty($state['ID'])) {
            DebMes("Queue watchdog: restarting queue of terminal $tid", 'terminals');
            terminalsQueueSchedule($tid, 0);
        } else {
            SQLExec("DELETE FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0");
        }
    }
}

// Запуск системы (startup): никто не говорит, сохранённое состояние плееров устарело
function terminalsQueueReset()
{
    SQLExec("DELETE FROM terminals_queue WHERE STARTED>0");
    SQLExec("DELETE FROM terminals_state");
    terminalsQueueExpire();
    $ids = SQLSelect("SELECT DISTINCT TERMINAL_ID FROM terminals_queue");
    foreach ($ids as $row) {
        terminalsQueueSchedule((int)$row['TERMINAL_ID'], 0);
    }
}

function terminalsQueueLock($tid)
{
    $rec = SQLSelectOne("SELECT GET_LOCK('" . DBSafe(DB_NAME . '_terminals_queue_' . (int)$tid) . "', 5) AS L");
    return !empty($rec['L']);
}

function terminalsQueueUnlock($tid)
{
    SQLSelectOne("SELECT RELEASE_LOCK('" . DBSafe(DB_NAME . '_terminals_queue_' . (int)$tid) . "') AS L");
}
