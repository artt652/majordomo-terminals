<?php
/*
 * Очередь сообщений терминалов (таблицы terminals_queue и terminals_state).
 *
 * Работает только для терминалов, дополнение TTS которых объявляет useQueue() и возвращает true.
 * Очередь разбирает отложенное задание (setTimeOut → cycle_scheduler), отдельного цикла нет.
 * - полосы (terminalsQueueLanes): voice — голос и звуки, video — ролики, image — картинки и текст без озвучки,
 *   camera — камеры. В полосе — по порядку, между полосами — по правилам terminalsQueueRelation(): голос
 *   останавливает ролик и камеру, картинка и камера — ролик; ролик ждёт всех; одновременно — то, что дополнение
 *   объявило в queueLanes() (например, окно картинки поверх ролика). Остановленное после запускается заново
 *   (ролик — с начала). Дополнение без queueLanes() — одна полоса на всё, как раньше;
 * - срочные (ask и уровень >= terminals::QUEUE_URGENT_LEVEL) звучащее не прерывают: идут следующими,
 *   раньше обычных, между собой — по порядку;
 * - повтор: та же фраза, что сейчас звучит или уже ждёт, второй раз не ставится; готовый файл
 *   голоса этой фразы (SAY_CACHED_READY) звучит сразу, если её текст уже на экране,
 *   или присоединяется к ожидающей записи; повтор той же камеры продлевает её окно;
 * - полоса занята, только если дополнение сообщило, что проиграло запись (вернуло истину);
 * - музыка: если у дополнения есть saveState()/restoreState(), перед первой записью полос, которые её
 *   останавливают (все, кроме image; без queueLanes() — все), состояние плеера сохраняется, а когда в них
 *   ничего не осталось — восстанавливается;
 * - сигнал перед сообщением звучит перед голосом, только если голос до этого молчал;
 * - сообщения старше terminals::QUEUE_TTL секунд не проигрываются; сторож (MINUTELY) запускает
 *   застрявшую очередь, startup сбрасывает «занято»;
 * - конец записи — расчётное время (длина файла + 1 с или оценка по тексту). Если у дополнения
 *   есть isPlaying() и плеер к этому времени ещё играет голос, ожидание продлевается по 2 с,
 *   не больше terminals::QUEUE_PLAYING_CHECKS раз. Нет isPlaying() или плеер не ответил —
 *   остаётся расчётное время;
 * - вызовы методов дополнения (terminalsQueueCall(): sendImage, sendSound, sendWebImage, sendVideo и методы,
 *   которые дополнение объявило в queueMethods()) встают в очередь своей полосы;
 * - номера записей вызывающего модуля ($refs, через запятую): когда запись отдана устройству — событие
 *   TERMINAL_DELIVERED (terminal, terminal_id, refs), например «доставлено» в переписке.
 */

// Методы дополнения, которые можно ставить в очередь через terminalsQueueCall(): общие и те, что дополнение
// объявило само (необязательный метод queueMethods() — список имён)
function terminalsQueueCallMethods($tts = null)
{
    $methods = array('sendImage', 'sendSound', 'sendWebImage', 'sendVideo');
    if (is_object($tts) && method_exists($tts, 'queueMethods')) {
        $methods = array_merge($methods, array_values(array_filter((array)$tts->queueMethods(), 'is_string')));
    }
    return array_values(array_unique($methods));
}

// Вызвать метод дополнения TTS терминала через очередь (для сценариев):
//   terminalsQueueCall('Кухня', 'sendImage', array('cms/cached/snapshot.jpg', 'Звонок в дверь'));
// $terminal — имя, название или запись терминала; $args — аргументы метода, как при прямом вызове.
// $options: 'level' — уровень (>= QUEUE_URGENT_LEVEL — срочно: следующим, раньше обычных);
//           'busy' — сколько секунд полоса занята после вызова (по умолчанию у sendSound —
//           длительность окна, 4-й аргумент, иначе 15 с; у sendVideo — duration из опций, иначе длина файла
//           или 60 с; у картинок 0 — следующая сразу);
//           'lane' — полоса (voice, video, image, camera), если не подходит выбранная по методу;
//           'refs' — номера записей вызывающего модуля: отдано устройству — событие TERMINAL_DELIVERED.
// Дополнение без очереди (useQueue) — метод вызывается сразу. Возвращает результат метода
// (или true — поставлено в очередь), false — нет терминала, дополнения или такого метода.
function terminalsQueueCall($terminal, $method, $args = array(), $options = array())
{
    $terminal_rec = is_array($terminal) ? $terminal : terminalsFind($terminal);
    if (empty($terminal_rec['ID'])) {
        return false;
    }
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts) || !method_exists($tts, $method) || !in_array($method, terminalsQueueCallMethods($tts), true)) {
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
        $seconds = (isset($args[0]) && is_string($args[0]) && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $args[0])) ? terminalsQueueFileSeconds($args[0]) : 0;
        $busy = (isset($args[4]) && is_array($args[4]) && !empty($args[4]['duration'])) ? (int)$args[4]['duration'] : (($seconds > 0) ? $seconds + 1 : 60);
    } else {
        $busy = 0;
    }
    $call = array('method' => $method, 'args' => $args, 'busy' => max(0, $busy));
    if (!empty($options['lane']) && in_array($options['lane'], array('voice', 'video', 'image', 'camera'), true)) {
        $call['lane'] = $options['lane'];
    }
    return terminalsQueueAdd($terminal_rec, 'call', $call, isset($options['level']) ? (int)$options['level'] : 0, '',
        '', '', isset($options['refs']) ? (string)$options['refs'] : '');
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

// Отвечает ли устройство терминала. Выключенное устройство не ждём: дополнения ждут соединения
// по 2–10 с на каждый запрос (у app_player — свои ожидания), и отправка во «Все терминалы» складывала бы их.
// Проверка — соединение с портом устройства, не дольше 1 с; не ответило — следующие 60 с к нему не
// обращаемся (cms/cached/terminals_unreachable.json), сообщение остаётся в переписке без «доставлено».
// Адрес даёт дополнение (deviceAddress(): хост и порт); нет такого метода, нет адреса или устройство
// на самом сервере — считается доступным
function terminalsReachable($terminal_rec, $tts = null)
{
    if (!is_object($tts) || !method_exists($tts, 'deviceAddress')) {
        return true;
    }
    list($host, $port) = $tts->deviceAddress();
    $host = trim((string)$host);
    if ($host == '' || (int)$port <= 0 || in_array(strtolower($host), array('127.0.0.1', 'localhost', '::1'), true)) {
        return true;
    }
    $key = $host . ':' . (int)$port;
    $file = ROOT . 'cms/cached/terminals_unreachable.json';
    $down = is_file($file) ? json_decode((string)@file_get_contents($file), true) : array();
    $down = is_array($down) ? $down : array();
    if (isset($down[$key]) && $down[$key] > time() - 60) {
        return false;
    }
    $fp = @fsockopen($host, (int)$port, $errno, $errstr, 1);
    if ($fp) {
        fclose($fp);
        if (isset($down[$key])) {
            unset($down[$key]);
            @file_put_contents($file, json_encode($down));
        }
        return true;
    }
    $down[$key] = time();
    foreach ($down as $k => $time) {
        if ($time < time() - 60) {
            unset($down[$k]);
        }
    }
    @file_put_contents($file, json_encode($down));
    DebMes('Terminal ' . $terminal_rec['TITLE'] . ' (' . $key . ') is not reachable — skipped for 60 s', 'terminals');
    return false;
}

// Адрес устройства терминала для журнала: «хост:порт» дополнения (deviceAddress()), иначе HOST терминала
function terminalsDeviceLabel($terminal_rec, $tts = null)
{
    if (is_object($tts) && method_exists($tts, 'deviceAddress')) {
        list($host, $port) = $tts->deviceAddress();
        if (trim((string)$host) != '') {
            return trim((string)$host) . ((int)$port > 0 ? ':' . (int)$port : '');
        }
    }
    return (isset($terminal_rec['HOST']) && trim((string)$terminal_rec['HOST']) != '') ? trim((string)$terminal_rec['HOST']) : '-';
}

// Текст для журнала без секретов: пароль в ссылках (rtsp://user:pass@… → rtsp://user:***@…), подписи, ключи и пароли
// в параметрах (в том числе pwd= внешней ссылки WebTorrent и id= гостевой ссылки на камеру)
function terminalsLogSafe($text)
{
    $text = preg_replace('~(\b[a-z][a-z0-9+.-]*://[^/:@\s"\']*):[^/@\s"\']*@~i', '$1:***@', (string)$text);
    $text = preg_replace('/([?&]id=)[0-9a-f]{32}\b/i', '$1***', $text);
    return preg_replace('/([?&#](?:token|sig|key|password|pass|pwd)=)[^&\s"\']+/i', '$1***', $text);
}

// Просит ли дополнение очередь
function terminalsQueueUsed($tts)
{
    return is_object($tts) && method_exists($tts, 'useQueue') && $tts->useQueue();
}

// Полосы очереди: voice — голос, звуки, голосовые; video — ролики; image — картинки и текст без озвучки;
// camera — потоки с камер. Музыка (то, что играл плеер до сообщений) — не полоса: её сохраняет saveState()
// и возвращает restoreState(). В полосе — по порядку, полосы между собой — по правилам terminalsQueueRelation().
// Дополнение объявляет свои полосы необязательным методом queueLanes():
//   return array('voice', 'video', 'camera');                       // одно устройство: всё по очереди
//   return array('voice', 'image', 'video', 'camera',
//       'parallel' => array('voice+image', 'voice+camera', 'image+video', 'image+camera'));  // окно поверх экрана
// 'parallel' — пары полос, которые устройство показывает одновременно. Полоса, которой дополнение не объявило,
// идёт в voice; картинки (image), если их полосы нет, ничего не ждут. Без queueLanes() — как раньше: одна
// полоса на всё, только текст без озвучки (text) не держит голос.
function terminalsQueueLanes($tts)
{
    if (!is_object($tts) || !method_exists($tts, 'queueLanes')) {
        return array('lanes' => array('voice', 'image'), 'parallel' => array('image+voice'), 'music' => array('voice', 'image'));
    }
    $lanes = array('voice');
    $parallel = array();
    foreach ((array)$tts->queueLanes() as $key => $value) {
        if ($key === 'parallel') {
            foreach ((array)$value as $pair) {
                $pair = explode('+', strtolower(str_replace(' ', '', (string)$pair)));
                if (count($pair) == 2) {
                    sort($pair);
                    $parallel[] = implode('+', $pair);
                }
            }
        } elseif (is_string($value) && in_array($value, array('voice', 'video', 'image', 'camera'), true)) {
            $lanes[] = $value;
        }
    }
    $lanes = array_values(array_unique($lanes));
    // картинка музыку не останавливает
    return array('lanes' => $lanes, 'parallel' => $parallel, 'music' => array_values(array_diff($lanes, array('image'))));
}

// Полоса записи: $kind и метод вызова (terminalsQueueCall: 'lane' в опциях или по методу)
function terminalsQueueLane($lanes, $kind, $message)
{
    if ($kind == 'text') {
        $lane = 'image';
    } elseif ($kind == 'call' && is_array($message)) {
        $method = isset($message['method']) ? (string)$message['method'] : '';
        $args = isset($message['args']) ? array_values((array)$message['args']) : array();
        if (!empty($message['lane'])) {
            $lane = (string)$message['lane'];
        } elseif ($method == 'sendImage' || $method == 'sendWebImage') {
            $lane = 'image';
        } elseif ($method == 'sendVideo') {
            // поток: rtsp/rtmp-ссылка или тип 'stream' (4-й аргумент) — камера
            $uri = isset($args[0]) ? (string)$args[0] : '';
            $stream = preg_match('/^(rtsp|rtsps|rtmp|rtmps):\/\//i', $uri) || (isset($args[3]) && $args[3] == 'stream');
            $lane = $stream ? 'camera' : 'video';
        } else {
            $lane = 'voice';
        }
    } else {
        $lane = 'voice';
    }
    if ($lane == 'image') {
        return 'image';
    }
    return in_array($lane, $lanes['lanes'], true) ? $lane : 'voice';
}

// Что делать записи полосы $incoming, пока звучит или показывается запись полосы $active:
// 'order' — та же полоса, по порядку; 'parallel' — одновременно; 'wait' — ждать её конца;
// 'pause' — остановить её, после — запустить заново (ролик — с начала, камеру — снова).
// Без одновременности: голос важнее всего, камера и картинка ждут друг друга, ролик — всех
function terminalsQueueRelation($incoming, $active, $lanes)
{
    if ($incoming == $active) {
        return 'order';
    }
    $pair = array($incoming, $active);
    sort($pair);
    if (in_array(implode('+', $pair), $lanes['parallel'], true)
        || (($incoming == 'image' || $active == 'image') && !in_array('image', $lanes['lanes'], true))) {
        return 'parallel';
    }
    $rank = array('voice' => 3, 'camera' => 2, 'image' => 2, 'video' => 1);
    return $rank[$incoming] > $rank[$active] ? 'pause' : 'wait';
}

// Срочная запись (ask или уровень >= QUEUE_URGENT_LEVEL) — условие SQL для порядка
function terminalsQueueUrgentSql()
{
    return "(KIND='ask' OR LEVEL>=" . (int)terminals::QUEUE_URGENT_LEVEL . ")";
}

// $kind: say, ask, text (текст или картинка без озвучки — полоса image), cached (голос фразы), sound (звуковой файл),
// call (метод дополнения — terminalsQueueCall(), $message — массив method/args/busy/lane); $sender — от кого (заголовок окна);
// $message_ids — номера записей вызывающего модуля (через запятую): когда запись отдана устройству — событие TERMINAL_DELIVERED;
// $duration — длительность звукового файла, если известна заранее (например, у записей из браузера её часто нет
// в самом файле), иначе — из файла (terminals_media.inc.php)
function terminalsQueueAdd($terminal_rec, $kind, $message, $level, $file, $image = '', $sender = '', $message_ids = '', $duration = 0)
{
    $duration = max(0, min(3600, (int)ceil((float)$duration)));
    $message_ids = implode(',', array_filter(array_map('intval', explode(',', (string)$message_ids))));
    $tid = isset($terminal_rec['ID']) ? (int)$terminal_rec['ID'] : 0;
    $urgent = ($kind == 'ask' || (int)$level >= terminals::QUEUE_URGENT_LEVEL);
    if (!$tid || !terminalsQueueLock($tid)) {
        // без ID или очередь занята другим процессом слишком долго — не теряем сообщение
        $played = terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file, $image, $sender, $duration);
        terminalsQueueDelivered($terminal_rec, $played[0], $message_ids);
        return $played[0];
    }
    $tts = terminalsQueueAddon($terminal_rec);
    $lane = terminalsQueueLane(terminalsQueueLanes($tts), $kind, $message);
    $now = time();
    $result = true;
    // текст хранится в JSON (\uXXXX): таблица в utf8, эмодзи иначе превратятся в «?»
    if ($kind == 'call') {
        $key = json_encode($message);
    } else {
        $key = json_encode(($kind == 'sound') ? 'sound:' . $file : (string)$message);
    }
    // последняя запись этой полосы, отданная устройству (отыгравшие хранятся QUEUE_SIGNAL_PAUSE секунд)
    $current = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND LANE='" . DBSafe($lane) . "' AND STARTED>0 ORDER BY STARTED DESC, ID DESC LIMIT 1");
    $busy = !empty($current['ID']) && (int)$current['FINISH'] > $now;

    if (!empty($current['ID']) && $current['MESSAGE'] === $key) {
        // голос фразы, которая уже отдана устройству, — к тому же сообщению
        if ($message_ids == '' && !empty($current['MESSAGE_IDS'])) {
            $message_ids = (string)$current['MESSAGE_IDS'];
        }
    }
    if ($busy && $current['MESSAGE'] === $key) {
        // та же фраза уже звучит — это сообщение тоже доставлено
        terminalsQueueDelivered($terminal_rec, true, $message_ids);
        if ($kind == 'cached' && $current['FILENAME'] == '') {
            // голос фразы, текст которой уже показан: звучит сразу, терминал занят дольше
            // (с картинкой этой фразы, если она была)
            $played = terminalsQueuePlay($terminal_rec, 'cached', $message, $level, $file, (string)$current['IMAGE'], (string)$current['SENDER']);
            $current['FILENAME'] = $file;
            $current['FINISH'] = max((int)$current['FINISH'], $now + $played[1]);
            SQLUpdate('terminals_queue', $current);
            $result = $played[0];
        } elseif ($lane == 'camera') {
            // та же камера ещё показывается — повторный вызов продлевает окно
            $played = terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file);
            $current['FINISH'] = max((int)$current['FINISH'], $now + $played[1]);
            SQLUpdate('terminals_queue', $current);
            $result = $played[0];
        }
        terminalsQueueScheduleNext($tid);
        terminalsQueueUnlock($tid);
        return $result;
    }

    $waiting = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED=0 AND MESSAGE='" . DBSafe($key) . "' ORDER BY ID LIMIT 1");
    if (!empty($waiting['ID']) && $urgent && empty($waiting['PAUSED']) && (int)$waiting['LEVEL'] < terminals::QUEUE_URGENT_LEVEL && $waiting['KIND'] != 'ask') {
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
        'LANE' => $lane,
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
        'PAUSED' => 0,
        'CHECKS' => 0,
    );
    $rec['ID'] = SQLInsert('terminals_queue', $rec);
    $started = terminalsQueueRun($terminal_rec, $tts, $rec['ID']);
    if ($started === null) {
        // ждёт своей очереди
        DebMes('Queued for ' . $terminal_rec['TITLE'] . " ($lane): "
            . terminalsLogSafe(is_array($message) ? json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $message), 'terminals');
    } else {
        $result = $started;
    }
    terminalsQueueUnlock($tid);
    return $result;
}

// Следующее сообщение очереди терминала (вызывается отложенным заданием; $check — от прежних версий, не нужен)
function terminalsQueueNext($tid, $check = 0)
{
    $tid = (int)$tid;
    if (!$tid || !terminalsQueueLock($tid)) {
        return;
    }
    $terminal_rec = getTerminalByID($tid);
    if (empty($terminal_rec['ID'])) {
        SQLExec("DELETE FROM terminals_queue WHERE TERMINAL_ID=$tid");
        SQLExec("DELETE FROM terminals_state WHERE TERMINAL_ID=$tid");
        terminalsQueueUnlock($tid);
        return;
    }
    terminalsQueueRun($terminal_rec, terminalsQueueAddon($terminal_rec));
    terminalsQueueUnlock($tid);
}

// Разобрать очередь терминала (под блокировкой): отыгравшее убрать, в каждой полосе запустить следующее,
// если позволяют другие полосы (срочные — раньше обычных, но звучащее не прерывают), остановленное
// вернуть, когда освободится, музыку вернуть, когда в полосах, которые её останавливают, ничего не осталось.
// Возвращает результат дополнения для записи $new_id, если она запущена сейчас, иначе null
function terminalsQueueRun($terminal_rec, $tts, $new_id = 0)
{
    $tid = (int)$terminal_rec['ID'];
    $lanes = terminalsQueueLanes($tts);
    $checks = (int)terminals::QUEUE_PLAYING_CHECKS;
    $result = null;
    $now = time();
    // расчётное время голоса вышло, но плеер ещё играет сообщение — продлить на 2 с (не больше QUEUE_PLAYING_CHECKS раз)
    $ended = SQLSelect("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 AND LANE='voice' AND FINISH<=$now AND CHECKS<$checks");
    foreach ($ended as $item) {
        if ($now - (int)$item['FINISH'] < 2 * $checks && terminalsQueueIsPlaying($terminal_rec)) {
            $item['FINISH'] = $now + 2;
            $item['CHECKS'] = (int)$item['CHECKS'] + 1;
        } else {
            $item['CHECKS'] = $checks;
        }
        SQLUpdate('terminals_queue', $item);
    }
    // отыгравшие хранятся QUEUE_SIGNAL_PAUSE секунд: по ним — сигнал и повторы фраз
    SQLExec("DELETE FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 AND FINISH<" . ($now - (int)terminals::QUEUE_SIGNAL_PAUSE));
    terminalsQueueExpire($tid);

    $order = array('voice', 'camera', 'image', 'video');
    do {
        $progress = false;
        foreach ($order as $lane) {
            $now = time();
            $active = SQLSelect("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 AND FINISH>$now");
            $blocked = false;
            $pause = array();
            foreach ($active as $item) {
                $relation = terminalsQueueRelation($lane, $item['LANE'], $lanes);
                if ($relation == 'order' || $relation == 'wait') {
                    $blocked = true;
                    break;
                }
                if ($relation == 'pause') {
                    $pause[] = $item;
                }
            }
            if ($blocked) {
                continue;
            }
            // остановленное — первым, затем срочные, затем обычные, в порядке поступления
            $next = SQLSelectOne("SELECT * FROM terminals_queue WHERE TERMINAL_ID=$tid AND LANE='" . DBSafe($lane) . "' AND STARTED=0 ORDER BY PAUSED DESC, "
                . terminalsQueueUrgentSql() . " DESC, ID LIMIT 1");
            if (empty($next['ID'])) {
                continue;
            }
            $music = in_array($lane, $lanes['music'], true);
            if ($music) {
                // первая запись серии в полосах, которые останавливают музыку, — запомнить, что играло
                $other = SQLSelectOne("SELECT ID FROM terminals_queue WHERE TERMINAL_ID=$tid AND ID!=" . (int)$next['ID']
                    . " AND LANE IN ('" . implode("','", array_map('DBSafe', $lanes['music'])) . "') AND (PAUSED=1 OR FINISH>$now) LIMIT 1");
                if (empty($other['ID'])) {
                    terminalsQueueSaveState($terminal_rec);
                }
            }
            foreach ($pause as $item) {
                // остановить: после запустится заново
                if (is_object($tts) && method_exists($tts, 'stopLane')) {
                    $direct = terminalsPlayerDirect(true);
                    $tts->stopLane($item['LANE']);
                    terminalsPlayerDirect($direct);
                }
                SQLExec("UPDATE terminals_queue SET STARTED=0, FINISH=0, PAUSED=1, CHECKS=0 WHERE ID=" . (int)$item['ID']);
                DebMes('Paused on ' . $terminal_rec['TITLE'] . ': ' . $item['LANE'] . ' (for ' . $lane . ')', 'terminals');
            }
            if ($lane == 'voice' && empty($next['PAUSED']) && $next['KIND'] != 'sound' && $next['KIND'] != 'call') {
                // сигнал перед сообщением (настройка TTS «Сигнал перед сообщением»), если голос молчал
                // QUEUE_SIGNAL_PAUSE секунд: звучит сигнал, сообщение ждёт его конца
                $last = SQLSelectOne("SELECT MAX(FINISH) AS FINISH FROM terminals_queue WHERE TERMINAL_ID=$tid AND LANE='voice' AND STARTED>0");
                $signal = (empty($last['FINISH']) || $now - (int)$last['FINISH'] >= terminals::QUEUE_SIGNAL_PAUSE) ? terminalsQueueSignal($terminal_rec) : '';
                if ($signal != '') {
                    $sig = terminalsQueuePlay($terminal_rec, 'sound', '', (int)$next['LEVEL'], $signal);
                    if ($sig[1] > 0) {
                        $sig_rec = array('TERMINAL_ID' => $tid, 'LANE' => 'voice', 'KIND' => 'sound', 'MESSAGE' => json_encode('sound:' . $signal),
                            'LEVEL' => (int)$next['LEVEL'], 'FILENAME' => $signal, 'IMAGE' => '', 'SENDER' => '',
                            'ADDED' => $now, 'STARTED' => $now, 'FINISH' => $now + $sig[1], 'PAUSED' => 0, 'CHECKS' => 0);
                        SQLInsert('terminals_queue', $sig_rec);
                        $progress = true;
                        continue;
                    }
                }
            }
            $message = json_decode($next['MESSAGE'], true);
            if ($next['KIND'] != 'call' && !is_string($message)) {
                $message = '';
            }
            $played = terminalsQueuePlay($terminal_rec, $next['KIND'], $message, (int)$next['LEVEL'], $next['FILENAME'], (string)$next['IMAGE'],
                (string)$next['SENDER'], isset($next['DURATION']) ? (int)$next['DURATION'] : 0);
            if (empty($next['PAUSED'])) {
                terminalsQueueDelivered($terminal_rec, $played[0], isset($next['MESSAGE_IDS']) ? $next['MESSAGE_IDS'] : '');
            }
            if ((int)$next['ID'] == (int)$new_id) {
                $result = $played[0];
            }
            // дополнение ничего не проиграло — полоса свободна, сразу следующее
            $next['STARTED'] = $now;
            $next['FINISH'] = $now + ($played[0] ? $played[1] : 0);
            $next['PAUSED'] = 0;
            $next['CHECKS'] = $played[0] ? 0 : $checks;
            SQLUpdate('terminals_queue', $next);
            $progress = true;
        }
    } while ($progress);

    // музыку вернуть, когда в полосах, которые её останавливают, ничего не звучит, не ждёт и не остановлено
    $now = time();
    $left = SQLSelectOne("SELECT ID FROM terminals_queue WHERE TERMINAL_ID=$tid AND LANE IN ('" . implode("','", array_map('DBSafe', $lanes['music']))
        . "') AND (STARTED=0 OR FINISH>$now) LIMIT 1");
    if (empty($left['ID'])) {
        terminalsQueueRestoreState($terminal_rec);
    }
    terminalsQueueScheduleNext($tid);
    return $result;
}

// Задание очереди терминала — к ближайшему концу звучащей записи (одно задание на терминал)
function terminalsQueueScheduleNext($tid)
{
    $tid = (int)$tid;
    $next = SQLSelectOne("SELECT MIN(FINISH) AS FINISH FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0 AND FINISH>" . time());
    if (!empty($next['FINISH'])) {
        terminalsQueueSchedule($tid, (int)$next['FINISH'] - time());
    }
}


// Объединить списки номеров сообщений («1,2» + «3» = «1,2,3»)
function terminalsQueueIds($a, $b)
{
    $ids = array_unique(array_filter(array_map('intval', array_merge(explode(',', (string)$a), explode(',', (string)$b)))));
    return substr(implode(',', $ids), 0, 255);
}

// Дополнение приняло запись — событие TERMINAL_DELIVERED для вызывающего модуля (номера его записей)
function terminalsQueueDelivered($terminal_rec, $played, $refs)
{
    $refs = implode(',', array_filter(array_map('intval', explode(',', (string)$refs))));
    if ($played && $refs != '') {
        terminalsEvent('TERMINAL_DELIVERED', array('terminal' => isset($terminal_rec['NAME']) ? $terminal_rec['NAME'] : '',
            'terminal_id' => isset($terminal_rec['ID']) ? (int)$terminal_rec['ID'] : 0, 'refs' => $refs));
    }
}

// Отдать сообщение дополнению TTS. Возвращает [результат дополнения, сколько секунд терминал занят].
// Сообщение с картинкой уходит в showMessage(), если дополнение его умеет (текст, картинка и голос вместе)
function terminalsQueuePlay($terminal_rec, $kind, $message, $level, $file, $image = '', $sender = '', $duration = 0)
{
    $direct = terminalsPlayerDirect(true);
    $played = terminalsQueuePlayAddon($terminal_rec, $kind, $message, $level, $file, $image, $sender, $duration);
    terminalsPlayerDirect($direct);
    return $played;
}

// Команды плееру из очереди и её дополнений идут напрямую: terminalsPlayerCommand() их не задерживает.
// Возвращает прежнее значение (чтобы вернуть его после)
function terminalsPlayerDirect($on)
{
    $was = !empty($GLOBALS['terminals_player_direct']);
    $GLOBALS['terminals_player_direct'] = (bool)$on;
    return $was;
}

function terminalsQueuePlayAddon($terminal_rec, $kind, $message, $level, $file, $image = '', $sender = '', $duration = 0)
{
    $result = false;
    $busy = 0;
    $tts = terminalsQueueAddon($terminal_rec);
    $addr = terminalsDeviceLabel($terminal_rec, $tts);
    if (!is_object($tts) || !terminalsReachable($terminal_rec, $tts)) {
        DebMes('Not delivered to ' . $terminal_rec['TITLE'] . " ($addr): " . (is_object($tts) ? 'not reachable' : 'no TTS addon'), 'terminals');
        return array($result, $busy);
    }
    if ($kind == 'call') {
        // метод дополнения из сценария (terminalsQueueCall)
        $method = (is_array($message) && isset($message['method'])) ? (string)$message['method'] : '';
        if (in_array($method, terminalsQueueCallMethods($tts), true) && method_exists($tts, $method)) {
            DebMes('Calling ' . $method . ' on ' . $terminal_rec['TITLE'] . " ($addr): "
                . terminalsLogSafe(json_encode($message['args'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 'terminals');
            $result = call_user_func_array(array($tts, $method), isset($message['args']) ? array_values((array)$message['args']) : array());
            if ($result) {
                $busy = isset($message['busy']) ? (int)$message['busy'] : 0;
            }
        }
        return terminalsQueuePlayed($terminal_rec, $addr, $kind, $result, $busy);
    }
    if ($kind == 'sound') {
        // звуковой файл: дополнению, которое умеет playFile()
        if (method_exists($tts, 'playFile')) {
            DebMes("Playing sound to " . $terminal_rec['TITLE'] . " ($addr, level $level): $file", 'terminals');
            // длительность — 4-м аргументом: дополнение с окном (pipup) держит его не меньше; остальные его не берут
            $result = $tts->playFile($file, $level, $sender, $duration);
            if ($result) {
                $seconds = ($duration > 0) ? $duration : terminalsQueueFileSeconds($file);
                // длительность не узнали — 15 с (короткий сигнал проще переждать, чем наложить на длинную запись)
                $busy = ($seconds > 0) ? $seconds + 1 : 15;
            }
        }
        return terminalsQueuePlayed($terminal_rec, $addr, $kind, $result, $busy);
    }
    if (($image != '' || $sender != '') && method_exists($tts, 'showMessage')) {
        DebMes("Showing to " . $terminal_rec['TITLE'] . " ($addr, level $level): " . $message . ($sender != '' ? " (from: $sender)" : '') . ($image != '' ? " (image: $image)" : '') . ($file != '' ? " (file: $file)" : ''), 'terminals');
        $result = $tts->showMessage($message, ($kind == 'ask') ? 9999 : $level, $image, $file, $sender);
        if ($result) {
            $seconds = ($file != '') ? terminalsQueueFileSeconds($file) : 0;
            $busy = ($seconds > 0) ? $seconds + 1 : terminalsQueueSpeechSeconds($message);
        }
        return terminalsQueuePlayed($terminal_rec, $addr, $kind, $result, $busy);
    }
    if ($kind == 'say' || $kind == 'ask' || $kind == 'text') {
        DebMes("Saying to " . $terminal_rec['TITLE'] . " ($addr, level $level): " . $message, 'terminals');
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
        DebMes("Saying cached to " . $terminal_rec['TITLE'] . " ($addr, level $level): " . $message . " (file: $file)", 'terminals');
        $cached = $tts->sayCached($message, $level, $file);
        if ($cached) {
            $seconds = terminalsQueueFileSeconds($file);
            $busy = max($busy, ($seconds > 0) ? $seconds + 1 : terminalsQueueSpeechSeconds($message));
        }
        if ($kind == 'cached') {
            $result = $cached;
        }
    }
    return terminalsQueuePlayed($terminal_rec, $addr, $kind, $result, $busy);
}

// Итог terminalsQueuePlay(): дополнение не приняло — строка в журнал (с адресом устройства)
function terminalsQueuePlayed($terminal_rec, $addr, $kind, $result, $busy)
{
    if (!$result) {
        DebMes('Not delivered to ' . $terminal_rec['TITLE'] . " ($addr): addon returned no result ($kind)", 'terminals');
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

function terminalsQueueSchedule($tid, $seconds)
{
    setTimeOut('terminals_queue_' . (int)$tid,
        "include_once(DIR_MODULES . 'terminals/terminals.class.php'); terminalsQueueNext(" . (int)$tid . ");",
        max(0, (int)$seconds));
}

// Играет ли плеер терминала сообщение (только если дополнение умеет isPlaying() и знает ответ)
function terminalsQueueIsPlaying($terminal_rec)
{
    $tts = terminalsQueueAddon($terminal_rec);
    if (!is_object($tts) || !method_exists($tts, 'isPlaying')) {
        return false;
    }
    $direct = terminalsPlayerDirect(true);
    $playing = $tts->isPlaying();
    terminalsPlayerDirect($direct);
    return $playing === true;
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

// Удалить ожидающие сообщения старше срока жизни (остановленные — нет: они уже начаты)
function terminalsQueueExpire($tid = 0)
{
    $where = "STARTED=0 AND PAUSED=0 AND ADDED<" . (time() - terminals::QUEUE_TTL);
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
    $direct = terminalsPlayerDirect(true);
    $state = $tts->saveState();
    terminalsPlayerDirect($direct);
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

// Дописать значение в сохранённое состояние серии (например, pipup: «экран будил модуль»); нет состояния — ничего
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
    $state = is_array($state) ? $state : array();
    // команды плееру, пришедшие во время сообщений (terminalsPlayerCommand)
    $commands = isset($state['commands']) ? (array)$state['commands'] : array();
    if (!empty($state['paused'])) {
        // во время сообщений нажали паузу — музыку не включать
        unset($state['file'], $state['time'], $state['track_id']);
    }
    unset($state['commands'], $state['paused']);
    $tts = terminalsQueueAddon($terminal_rec);
    $direct = terminalsPlayerDirect(true);
    if (!empty($state) && is_object($tts) && method_exists($tts, 'restoreState')) {
        DebMes('Restoring player state for ' . $terminal_rec['TITLE'] . ': ' . json_encode($state), 'terminals');
        $tts->restoreState($state);
    }
    foreach ($commands as $command) {
        if (is_array($command) && isset($command[0])) {
            DebMes('Player command after messages on ' . $terminal_rec['TITLE'] . ': ' . $command[0] . ' ' . (isset($command[1]) ? $command[1] : ''), 'terminals');
            include_once(DIR_MODULES . 'app_player/app_player.class.php');
            $player = new app_player();
            $player->play_terminal = $terminal_rec['NAME'];
            $player->command = (string)$command[0];
            $player->param = isset($command[1]) ? (string)$command[1] : '';
            $player->ajax = true;
            $player->intCall = true;
            $player->usual($out);
        }
    }
    terminalsPlayerDirect($direct);
}

// Команда плееру терминала (из app_player::usual()), пока звучат сообщения очереди: не прерывать их.
// play с файлом — это и включится после сообщений; play без файла и pause — включать ли музыку после;
// stop — после сообщений музыку не возвращать; set_volume — громкость после; next, previous, seek и действия
// с плейлистом — выполнить после возврата. Остальное (status, pl_get, features…) и команды самой очереди — сразу.
// Возвращает true, если команда отложена (app_player отвечает «queued»), false — выполнять как обычно
function terminalsPlayerCommand($terminal_rec, $command, $param = '')
{
    $command = strtolower((string)$command);
    $later = array('play', 'safe_play', 'pause', 'stop', 'set_volume', 'next', 'previous', 'seek',
        'pl_add', 'pl_delete', 'pl_empty', 'pl_play', 'pl_random', 'pl_loop', 'pl_repeat');
    if (!empty($GLOBALS['terminals_player_direct']) || empty($terminal_rec['ID']) || !in_array($command, $later, true)) {
        return false;
    }
    $tts = terminalsQueueAddon($terminal_rec);
    if (!terminalsQueueUsed($tts)) {
        return false;
    }
    $tid = (int)$terminal_rec['ID'];
    $lanes = terminalsQueueLanes($tts);
    if (!terminalsQueueLock($tid)) {
        return false;
    }
    $now = time();
    $busy = SQLSelectOne("SELECT ID FROM terminals_queue WHERE TERMINAL_ID=$tid AND LANE IN ('" . implode("','", array_map('DBSafe', $lanes['music']))
        . "') AND (PAUSED=1 OR (STARTED>0 AND FINISH>$now)) LIMIT 1");
    if (empty($busy['ID'])) {
        terminalsQueueUnlock($tid);
        return false;
    }
    $saved = SQLSelectOne("SELECT * FROM terminals_state WHERE TERMINAL_ID=$tid LIMIT 1");
    $state = !empty($saved['ID']) ? json_decode($saved['PLAYER_STATE'], true) : array();
    $state = is_array($state) ? $state : array();
    $param = (string)$param;
    if (($command == 'play' || $command == 'safe_play') && $param != '') {
        $state['file'] = $param;
        unset($state['time'], $state['track_id'], $state['paused'], $state['commands']);
    } elseif ($command == 'play' || $command == 'safe_play') {
        unset($state['paused']);
    } elseif ($command == 'pause') {
        $state['paused'] = 1;
    } elseif ($command == 'stop') {
        unset($state['file'], $state['time'], $state['track_id'], $state['paused'], $state['commands']);
    } elseif ($command == 'set_volume') {
        $state['volume'] = (int)$param;
    } else {
        $state['commands'][] = array($command, $param);
    }
    if (!empty($saved['ID'])) {
        $saved['PLAYER_STATE'] = json_encode($state);
        SQLUpdate('terminals_state', $saved);
    } else {
        SQLInsert('terminals_state', array('TERMINAL_ID' => $tid, 'PLAYER_STATE' => json_encode($state), 'SAVED' => $now));
    }
    DebMes('Player command during messages on ' . $terminal_rec['TITLE'] . " — after them: $command $param", 'terminals');
    terminalsQueueUnlock($tid);
    return true;
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
        $current = SQLSelectOne("SELECT MAX(FINISH) AS FINISH FROM terminals_queue WHERE TERMINAL_ID=$tid AND STARTED>0");
        if (!empty($current['FINISH']) && (int)$current['FINISH'] + terminals::QUEUE_WATCHDOG > $now) {
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

// Запуск системы (startup): никто не говорит, сохранённое состояние плееров устарело; остановленное — не возвращаем
function terminalsQueueReset()
{
    SQLExec("DELETE FROM terminals_queue WHERE STARTED>0 OR PAUSED=1");
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
