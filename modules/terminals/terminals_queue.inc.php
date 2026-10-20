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
 *   застрявшую очередь, startup сбрасывает «занято»;
 * - конец сообщения — расчётное время (длина файла + 1 с или оценка по тексту). Если у дополнения
 *   есть isPlaying() и плеер к этому времени ещё играет сообщение, ожидание продлевается по 2 с,
 *   не больше terminals::QUEUE_PLAYING_CHECKS раз. Нет isPlaying() или плеер не ответил —
 *   остаётся расчётное время.
 * - вызовы методов дополнения (terminalsQueueCall(): sendImage, sendSound, sendWebImage, sendVideo
 *   у pipup) встают в ту же очередь: ждут конца текущего сообщения, звук и видео держат терминал своё
 *   время, картинка — нет (только порядок). Видеопотоки (камеры) в очередь не ставятся — их окно
 *   продлевается повторным вызовом с тем же id, их отправляют напрямую.
 */

// Методы дополнения, которые можно ставить в очередь через terminalsQueueCall()
function terminalsQueueCallMethods()
{
    return array('sendImage', 'sendSound', 'sendWebImage', 'sendVideo');
}

// Вызвать метод дополнения TTS терминала через очередь (для сценариев):
//   terminalsQueueCall('Кухня', 'sendImage', array('cms/cached/snapshot.jpg', 'Звонок в дверь'));
// $terminal — имя, название или запись терминала; $args — аргументы метода, как при прямом вызове.
// $options: 'level' — уровень (>= QUEUE_URGENT_LEVEL — срочно, прерывает обычное сообщение);
//           'busy' — сколько секунд терминал занят после вызова (по умолчанию у sendSound —
//           длительность окна, 4-й аргумент, иначе 15 с; у sendVideo — duration из опций, иначе 60 с;
//           у картинок 0 — следующее сразу);
//           'message_ids' — номера сообщений страницы «Сообщения»: отдано устройству — «доставлено».
// Дополнение без очереди (useQueue) — метод вызывается сразу. Возвращает результат метода
// (или true — поставлено в очередь), false — нет терминала, дополнения или такого метода.
function terminalsQueueCall($terminal, $method, $args = array(), $options = array())
{
    $terminal_rec = is_array($terminal) ? $terminal : (function_exists('terminalsFind') ? terminalsFind($terminal) : array());
    if (empty($terminal_rec['ID']) || !in_array($method, terminalsQueueCallMethods(), true)) {
        return false;
    }
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts) || !method_exists($tts, $method)) {
        return false;
    }
    $args = array_values((array)$args);
    if (!terminalsQueueUsed($tts)) {
        return call_user_func_array(array($tts, $method), $args);
    }
    $options = (array)$options;
    if (isset($options['busy'])) {
        $busy = (int)$options['busy'];
    } elseif ($method == 'sendSound') {
        $busy = isset($args[3]) ? (int)$args[3] : 15;
    } elseif ($method == 'sendVideo') {
        $busy = (isset($args[4]) && is_array($args[4]) && !empty($args[4]['duration'])) ? (int)$args[4]['duration'] : 60;
    } else {
        $busy = 0;
    }
    $call = array('method' => $method, 'args' => $args, 'busy' => max(0, $busy));
    return terminalsQueueAdd($terminal_rec, 'call', $call, isset($options['level']) ? (int)$options['level'] : 0, '',
        '', '', isset($options['message_ids']) ? (string)$options['message_ids'] : '');
}

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

// $kind: say, ask, cached (голос фразы), sound (звуковой файл — terminalsSend), call (метод дополнения —
// terminalsQueueCall(), $message — массив method/args/busy); $sender — от кого (заголовок окна);
// $message_ids — номера сообщений страницы «Сообщения» (через запятую): когда запись отдана устройству — «доставлено»;
// $duration — длительность звукового файла, если известна заранее (страница «Сообщения» присылает её для записей
// из браузера, в которых её нет), иначе — из файла (terminals_media.inc.php)
function terminalsQueueAdd($terminal_rec, $kind, $message, $level, $file, $image = '', $sender = '', $message_ids = '', $duration = 0)
{
    $duration = max(0, min(3600, (int)ceil((float)$duration)));
    $message_ids = implode(',', array_filter(array_map('intval', explode(',', (string)$message_ids))));
    $tid = isset($terminal_rec['ID']) ? (int)$terminal_rec['ID'] : 0;
    $urgent = ($kind == 'ask' || (int)$level >= terminals::QUEUE_URGENT_LEVEL);
    if (!$tid || !terminalsQueueLock($tid)) {
        // без ID или очередь занята другим процессом слишком долго — не теряем сообщение
        $played = terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file, $image, $sender, $duration);
        terminalsQueueDelivered($played[0], $message_ids);
        return $played[0];
    }
    $now = time();
    $result = true;
    // текст хранится в JSON (\uXXXX): таблица в utf8, эмодзи иначе превратятся в «?»
    if ($kind == 'call') {
        $key = json_encode($message);
    } else {
        $key = json_encode(($kind == 'sound') ? 'sound:' . $file : (string)$message);
    }
    $current = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 ORDER BY STARTED DESC, ID DESC LIMIT 1");
    $busy = !empty($current['ID']) && (int)$current['FINISH'] > $now;
    if (!$busy && !empty($current['ID']) && $now < (int)$current['FINISH'] + 2 * terminals::QUEUE_PLAYING_CHECKS
        && terminalsQueueIsPlaying($terminal_rec)) {
        // расчётное время вышло, но плеер ещё играет сообщение
        $current['FINISH'] = $now + 2;
        SQLUpdate('terminals_queue', $current);
        $busy = true;
    }

    if (!empty($current['ID']) && $current['MESSAGE'] === $key) {
        // голос фразы, которая уже отдана устройству, — к тому же сообщению
        if ($message_ids == '' && !empty($current['MESSAGE_IDS'])) {
            $message_ids = (string)$current['MESSAGE_IDS'];
        }
    }
    if ($busy && $current['MESSAGE'] === $key) {
        // та же фраза уже звучит — это сообщение тоже доставлено
        terminalsQueueDelivered(true, $message_ids);
        if ($kind == 'cached' && $current['FILENAME'] == '') {
            // голос фразы, текст которой уже показан: звучит сразу, терминал занят дольше
            // (с картинкой этой фразы, если она была)
            $played = terminalsQueuePlay($terminal_rec, 'cached', $message, $level, $file, (string)$current['IMAGE'], (string)$current['SENDER']);
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
        if ($image == '') {
            $image = (string)$waiting['IMAGE'];
        }
        if ($sender == '') {
            $sender = (string)$waiting['SENDER'];
        }
        $message_ids = terminalsQueueIds($waiting['MESSAGE_IDS'], $message_ids);
        SQLExec("DELETE FROM terminals_queue WHERE ID=" . (int)$waiting['ID']);
        $waiting = array();
    }
    if (!empty($waiting['ID'])) {
        // та же фраза уже ждёт: присоединяем к ней файл голоса и картинку, повтор не ставим
        $changed = false;
        if ($file != '' && $waiting['FILENAME'] == '') {
            $waiting['FILENAME'] = $file;
            $changed = true;
        }
        if ($image != '' && $waiting['IMAGE'] == '') {
            $waiting['IMAGE'] = $image;
            $changed = true;
        }
        if ($sender != '' && $waiting['SENDER'] == '') {
            $waiting['SENDER'] = $sender;
            $changed = true;
        }
        $ids = terminalsQueueIds($waiting['MESSAGE_IDS'], $message_ids);
        if ($ids != $waiting['MESSAGE_IDS']) {
            $waiting['MESSAGE_IDS'] = $ids;
            $changed = true;
        }
        if ($changed) {
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
        'IMAGE' => (string)$image,
        'SENDER' => (string)$sender,
        'MESSAGE_IDS' => $message_ids,
        'DURATION' => $duration,
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
        // сигнал перед первым сообщением серии (настройка TTS «Сигнал перед сообщением»):
        // звучит сигнал, сообщение ждёт его конца
        $signal = (!$busy && $kind != 'sound' && $kind != 'call'
            && (empty($current['ID']) || $now - (int)$current['FINISH'] >= terminals::QUEUE_SIGNAL_PAUSE))
            ? terminalsQueueSignal($terminal_rec) : '';
        if ($signal != '') {
            $sig = terminalsQueuePlay($terminal_rec, 'sound', '', $level, $signal);
            if ($sig[1] > 0) {
                $sig_rec = array('TERMINAL_ID' => $tid, 'KIND' => 'sound', 'MESSAGE' => json_encode('sound:' . $signal),
                    'LEVEL' => (int)$level, 'FILENAME' => $signal, 'IMAGE' => '', 'SENDER' => '',
                    'ADDED' => $now, 'STARTED' => $now, 'FINISH' => $now + $sig[1]);
                SQLInsert('terminals_queue', $sig_rec);
                SQLInsert('terminals_queue', $rec);
                terminalsQueueSchedule($tid, $sig[1]);
                terminalsQueueUnlock($tid);
                return true;
            }
        }
        $rec['STARTED'] = $now;
        $played = terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file, $image, $sender, $duration);
        terminalsQueueDelivered($played[0], $message_ids);
        $rec['FINISH'] = $now + $played[1];
        SQLInsert('terminals_queue', $rec);
        $result = $played[0];
        terminalsQueueScheduleAfter($tid, $played[1]);
    } else {
        // ждём своей очереди
        SQLInsert('terminals_queue', $rec);
        terminalsQueueSchedule($tid, $busy ? (int)$current['FINISH'] - $now : 0);
        DebMes('Queued for ' . $terminal_rec['TITLE'] . ': ' . (is_array($message) ? json_encode($message) : $message), 'terminals');
    }
    terminalsQueueUnlock($tid);
    return $result;
}

// Следующее сообщение очереди терминала (вызывается отложенным заданием)
function terminalsQueueNext($tid, $check = 0)
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
    if (!empty($current['ID']) && $check < terminals::QUEUE_PLAYING_CHECKS && terminalsQueueIsPlaying($terminal_rec)) {
        // расчётное время вышло, но плеер ещё играет сообщение — проверим снова через 2 с
        $current['FINISH'] = $now + 2;
        SQLUpdate('terminals_queue', $current);
        terminalsQueueSchedule($tid, 2, $check + 1);
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
        if ($next['KIND'] != 'call' && !is_string($message)) {
            $message = '';
        }
        $played = terminalsQueuePlay($terminal_rec, $next['KIND'], $message, (int)$next['LEVEL'], $next['FILENAME'], (string)$next['IMAGE'],
            (string)$next['SENDER'], isset($next['DURATION']) ? (int)$next['DURATION'] : 0);
        terminalsQueueDelivered($played[0], isset($next['MESSAGE_IDS']) ? $next['MESSAGE_IDS'] : '');
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

// Объединить списки номеров сообщений («1,2» + «3» = «1,2,3»)
function terminalsQueueIds($a, $b)
{
    $ids = array_unique(array_filter(array_map('intval', array_merge(explode(',', (string)$a), explode(',', (string)$b)))));
    return substr(implode(',', $ids), 0, 255);
}

// Дополнение приняло запись очереди — её сообщения страницы «Сообщения» доставлены
function terminalsQueueDelivered($played, $message_ids)
{
    if ($played && $message_ids != '' && function_exists('terminalsMessagesDelivered')) {
        terminalsMessagesDelivered($message_ids);
    }
}

// Отдать сообщение дополнению TTS. Возвращает [результат дополнения, сколько секунд терминал занят].
// Сообщение с картинкой уходит в showMessage(), если дополнение его умеет (текст, картинка и голос вместе)
function terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file, $image = '', $sender = '', $duration = 0)
{
    $result = false;
    $busy = 0;
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts)) {
        return array($result, $busy);
    }
    if ($kind == 'call') {
        // метод дополнения из сценария (terminalsQueueCall)
        $method = (is_array($message) && isset($message['method'])) ? (string)$message['method'] : '';
        if (in_array($method, terminalsQueueCallMethods(), true) && method_exists($tts, $method)) {
            DebMes('Calling ' . $method . ' on ' . $terminal_rec['TITLE'] . ': ' . json_encode($message['args']), 'terminals');
            $result = call_user_func_array(array($tts, $method), isset($message['args']) ? array_values((array)$message['args']) : array());
            if ($result) {
                $busy = isset($message['busy']) ? (int)$message['busy'] : 0;
            }
        }
        return array($result, $busy);
    }
    if ($kind == 'sound') {
        // звуковой файл (terminalsSend): дополнению, которое умеет playFile()
        if (method_exists($tts, 'playFile')) {
            DebMes("Playing sound to " . $terminal_rec['TITLE'] . " (level $level): $file", 'terminals');
            // длительность — 4-м аргументом: pipup держит окно (и звук) не меньше; остальные дополнения его не берут
            $result = $tts->playFile($file, $level, $sender, $duration);
            if ($result) {
                $seconds = ($duration > 0) ? $duration : terminalsQueueFileSeconds($file);
                // длительность не узнали — 15 с (короткий сигнал проще переждать, чем наложить на длинную запись)
                $busy = ($seconds > 0) ? $seconds + 1 : 15;
            }
        }
        return array($result, $busy);
    }
    if (($image != '' || $sender != '') && method_exists($tts, 'showMessage')) {
        DebMes("Showing to " . $terminal_rec['TITLE'] . ' (level ' . $level . '): ' . $message . ($sender != '' ? " (from: $sender)" : '') . ($image != '' ? " (image: $image)" : '') . ($file != '' ? " (file: $file)" : ''), 'terminals');
        $result = $tts->showMessage($message, ($kind == 'ask') ? 9999 : $level, $image, $file, $sender);
        if ($result) {
            $seconds = ($file != '') ? terminalsQueueFileSeconds($file) : 0;
            $busy = ($seconds > 0) ? $seconds + 1 : terminalsQueueSpeechSeconds($message);
        }
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
            $seconds = terminalsQueueFileSeconds($file);
            $busy = max($busy, ($seconds > 0) ? $seconds + 1 : terminalsQueueSpeechSeconds($message));
        }
        if ($kind == 'cached') {
            $result = $cached;
        }
    }
    return array($result, $busy);
}

// Длительность файла в целых секундах (вверх); путь — от корня диска или от веб-корня; 0 — не узнали
function terminalsQueueFileSeconds($file)
{
    $file = (string)$file;
    $local = is_file($file) ? $file : (($file != '' && is_file(ROOT . ltrim($file, '/'))) ? ROOT . ltrim($file, '/') : '');
    return ($local != '') ? (int)ceil(terminalsMediaDuration($local)) : 0;
}

// Примерная длительность речи: ~12 символов в секунду + 1 с
function terminalsQueueSpeechSeconds($message)
{
    $message = (string)$message;
    $len = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    return (int)ceil($len / 12) + 1;
}

function terminalsQueueSchedule($tid, $seconds, $check = 0)
{
    setTimeOut('terminals_queue_' . (int)$tid,
        "include_once(DIR_MODULES . 'terminals/terminals.class.php'); terminalsQueueNext(" . (int)$tid . ", " . (int)$check . ");",
        max(0, (int)$seconds));
}

// Играет ли плеер терминала сообщение (только если дополнение умеет isPlaying() и знает ответ)
function terminalsQueueIsPlaying($terminal_rec)
{
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts) || !method_exists($tts, 'isPlaying')) {
        return false;
    }
    return $tts->isPlaying() === true;
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

// Файл сигнала перед сообщением из настроек TTS терминала (путь от веб-корня) или ''.
// У MAIN не звучит, если включён общий сигнал ядра (SETTINGS_SPEAK_SIGNAL): иначе он прозвучит дважды
function terminalsQueueSignal($terminal_rec)
{
    $settings = terminalsAddonSettings($terminal_rec, 'tts', $terminal_rec['TTS_TYPE']);
    $signal = isset($settings['SIGNAL']) ? trim((string)$settings['SIGNAL']) : '';
    if ($signal == '' || !is_file(ROOT . ltrim($signal, '/'))) {
        return '';
    }
    if ($terminal_rec['NAME'] == 'MAIN' && (!defined('SETTINGS_SPEAK_SIGNAL') || SETTINGS_SPEAK_SIGNAL == '1')) {
        return '';
    }
    $tts = terminalsQueueAddon($terminal_rec);
    return (is_object($tts) && method_exists($tts, 'playFile')) ? $signal : '';
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

// Дописать значение в сохранённое состояние серии (pipup: «экран будил модуль»); нет состояния — ничего
function terminalsQueueStateMark($tid, $key, $value)
{
    $saved = SQLSelectOne("SELECT * FROM terminals_state WHERE TERMINAL_ID=" . (int)$tid . " LIMIT 1");
    if (empty($saved['ID'])) {
        return;
    }
    $state = json_decode($saved['PLAYER_STATE'], true);
    $state = is_array($state) ? $state : array();
    $state[$key] = $value;
    $saved['PLAYER_STATE'] = json_encode($state);
    SQLUpdate('terminals_state', $saved);
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
