<?php
/*
 * Сообщение на терминал (или несколько) — из сценариев и из карточки терминала.
 *
 *   include_once(DIR_MODULES . 'terminals/terminals.class.php');
 *   terminalsSend('Кухня', 'Ужин готов', array(
 *       'from'  => 'Гостиная',                  // от кого: терминал (имя или название) или любой текст
 *       'image' => 'cms/cached/snapshot.jpg',   // картинка: путь в веб-корне MajorDoMo или http-ссылка
 *       'sound' => 'cms/sounds/dingdong.mp3',   // звук перед сообщением: путь в веб-корне или http-ссылка
 *       'file'  => 'cms/cached/terminals/x.pdf', // файл для страницы «Сообщения» (скачать); на устройство — «Файл: имя»
 *       'file_name' => 'Счёт.pdf',              // имя файла для показа и скачивания
 *       'voice' => true,                        // озвучить текст генератором речи (по умолчанию да)
 *       'level' => 5,                           // уровень; по умолчанию — порог терминала-получателя
 *       'chat'  => 'room:3',                    // чат на странице «Сообщения»: all, room:ID, group:ID (пусто — личный)
 *       'chat_title' => 'Кухня',                // название чата — добавляется к отправителю в заголовке окна
 *   ));
 *
 * Куда: имя, название или адрес терминала; можно массив.
 * Текст с озвучкой идёт как sayTo(): событие SAYTO, генераторы речи делают голос для получателя.
 * Без озвучки (voice => false) — только текст, сразу в очередь получателя.
 * Картинку и отправителя показывают дополнения с showMessage() (pipup: картинка в окне,
 * отправитель в заголовке), звук играют дополнения с playFile() (mediaplayer, *_tts, pipup).
 * Остальные получают текст, как обычно. Сообщение записывается и в историю (shouts).
 * Возвращает число терминалов, которым сообщение отправлено.
 */

function terminalsSend($to, $message, $options = array())
{
    if (!is_array($options)) {
        $options = array();
    }
    $message = trim((string)$message);
    $image = isset($options['image']) ? trim((string)$options['image']) : '';
    $sound = isset($options['sound']) ? trim((string)$options['sound']) : '';
    $voice = !isset($options['voice']) || !empty($options['voice']);
    $file = isset($options['file']) ? trim((string)$options['file']) : '';
    $file_name = isset($options['file_name']) ? trim((string)$options['file_name']) : '';
    if ($file != '' && $file_name == '') {
        $file_name = basename($file);
    }
    if ($message == '' && $image == '' && $sound == '' && $file == '') {
        return 0;
    }
    // отправитель: название терминала, если это терминал, иначе как есть
    $sender = isset($options['from']) ? trim((string)$options['from']) : '';
    $from = array();
    if ($sender != '') {
        $from = terminalsFind($sender);
        if (!empty($from['ID'])) {
            $sender = $from['TITLE'];
        }
    }
    // история сообщений — одна запись на отправку
    if ($message != '') {
        $shout = array(
            // таблица shouts в utf8 (3 байта): эмодзи и другие 4-байтовые символы она не принимает
            'MESSAGE' => preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $message),
            'IMAGE' => $image,
            'ADDED' => date('Y-m-d H:i:s'),
            'ROOM_ID' => 0,
            'MEMBER_ID' => 0,
            'SOURCE' => ($sender != '') ? 'terminal: ' . $sender : 'terminal',
        );
        if (isset($options['level']) && (int)$options['level'] > 0) {
            $shout['IMPORTANCE'] = (int)$options['level'];
        }
        SQLInsert('shouts', $shout);
    }
    $chat = isset($options['chat']) ? preg_replace('/[^a-z0-9:_]/i', '', (string)$options['chat']) : '';
    $send_id = md5(uniqid('', true) . mt_rand());
    $sender_title = $sender;
    if ($sender != '' && !empty($options['chat_title'])) {
        $sender_title = $sender . ' · ' . trim((string)$options['chat_title']);
    }
    $sent = 0;
    foreach ((array)$to as $destination) {
        $terminal_rec = terminalsFind($destination);
        if (empty($terminal_rec['ID'])) {
            DebMes('terminalsSend: terminal not found: ' . $destination, 'terminals');
            continue;
        }
        // терминал без «Может проговаривать» (например, только браузер со страницей «Сообщения»)
        // получает сообщение только в переписку — на его страницу, без доставки дополнению TTS
        $speaks = $terminal_rec['CANTTS'] && $terminal_rec['TTS_TYPE'] != '';
        $level = isset($options['level']) ? (int)$options['level'] : terminalsMinLevel($terminal_rec);
        DebMes('terminalsSend to ' . $terminal_rec['TITLE'] . ($sender != '' ? " from $sender" : '') . " (level $level): $message"
            . ($image != '' ? " (image: $image)" : '') . ($sound != '' ? " (sound: $sound)" : '') . ($speaks ? '' : ' (page only)'), 'terminals');
        $tts = $speaks ? terminalsQueueAddon($terminal_rec) : null;
        // звук перед сообщением
        if ($sound != '' && is_object($tts) && method_exists($tts, 'playFile')) {
            if (terminalsQueueUsed($tts)) {
                terminalsQueueAdd($terminal_rec, 'sound', '', $level, $sound, '', $sender_title);
            } else {
                $tts->playFile($sound, $level, $sender_title);
            }
        }
        // только файл: на устройство — текст «Файл: имя» без озвучки, сам файл — на странице «Сообщения»
        if ($speaks && $message == '' && $image == '' && $sound == '' && $file != '') {
            include_once(DIR_MODULES . 'terminals/terminals.class.php');
            $terminals = new terminals();
            $terminals->terminalSay($terminal_rec, terminalsLang('TERMINALS_FILE') . ': ' . $file_name, $level, '', $sender_title);
        }
        if ($speaks && ($message != '' || $image != '')) {
            if ($voice && $message != '') {
                // как sayTo(): терминалы и генераторы речи получают событие SAYTO
                processSubscriptionsSafe('SAYTO', array('level' => $level, 'message' => $message,
                    'destination' => $terminal_rec['NAME'], 'image' => $image, 'from' => $sender_title));
            } else {
                include_once(DIR_MODULES . 'terminals/terminals.class.php');
                $terminals = new terminals();
                $terminals->terminalSay($terminal_rec, $message, $level, $image, $sender_title);
            }
        }
        // переписка терминалов (страница «Сообщения»): текст хранится в JSON, чтобы не терять эмодзи
        $rec = array(
            'FROM_NAME' => isset($from['NAME']) && !empty($from['ID']) ? $from['NAME'] : $sender,
            'TO_NAME' => $terminal_rec['NAME'],
            'MESSAGE' => json_encode($message),
            'IMAGE' => $image,
            'SOUND' => $sound,
            'FILE' => $file,
            'FILE_NAME' => $file_name,
            'ADDED' => time(),
            'CHAT' => $chat,
            'SEND_ID' => $send_id,
        );
        $rec['ID'] = SQLInsert('terminals_messages', $rec);
        // открытые страницы «Сообщения» получают событие через вебсокет и сразу обновляют переписку
        if (function_exists('postToWebSocketQueue')) {
            postToWebSocketQueue('TERMINAL_MESSAGE', json_encode(array('ID' => (int)$rec['ID'],
                'FROM' => $rec['FROM_NAME'], 'TO' => $rec['TO_NAME'])), 'PostEvent');
        }
        $sent++;
    }
    return $sent;
}

// Терминал по имени, названию или адресу
function terminalsFind($name)
{
    $name = trim((string)$name);
    if ($name == '') {
        return array();
    }
    $list = getTerminalsByName($name, 1);
    if (!empty($list[0]['ID'])) {
        return $list[0];
    }
    $rec = SQLSelectOne("SELECT * FROM terminals WHERE TITLE='" . DBSafe($name) . "' LIMIT 1");
    if (!empty($rec['ID'])) {
        return $rec;
    }
    $list = getTerminalsByHost($name, 1);
    return !empty($list[0]['ID']) ? $list[0] : array();
}

// Порог уровня сообщений терминала (как в terminalSay): поле терминала или ThisComputer.minMsgLevel
function terminalsMinLevel($terminal_rec)
{
    $min_level = (int)getGlobal('ThisComputer.minMsgLevel');
    if ($terminal_rec['MIN_MSG_LEVEL']) {
        $min_level = (int)processTitle($terminal_rec['MIN_MSG_LEVEL']);
    }
    return max(0, $min_level);
}
