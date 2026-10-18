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
 *       'video' => 'cms/cached/terminals/x.mp4', // видео: на странице «Сообщения» — плеер, на устройстве —
 *                                               // окно видео, если у дополнения есть sendVideo() (pipup), иначе «Видео»
 *       'voice' => true,                        // озвучить текст генератором речи (по умолчанию да)
 *       'level' => 5,                           // уровень; по умолчанию — порог терминала-получателя
 *       'chat'  => 'room:3',                    // чат на странице «Сообщения»: all, room:ID, group:ID (пусто — личный)
 *       'chat_title' => 'Кухня',                // название чата — добавляется к отправителю в заголовке окна
 *       'shout' => true,                        // записать копию в общий чат MajorDoMo (для chat => 'all' — всегда)
 *   ));
 *
 * Куда: имя, название или адрес терминала; можно массив.
 * Текст с озвучкой идёт как sayTo(): событие SAYTO, генераторы речи делают голос для получателя.
 * Без озвучки (voice => false) — только текст, сразу в очередь получателя.
 * Картинку и отправителя показывают дополнения с showMessage() (pipup: картинка в окне,
 * отправитель в заголовке), звук играют дополнения с playFile() (mediaplayer, *_tts, pipup).
 * Остальные получают текст, как обычно.
 * В общий чат MajorDoMo (shouts) пишется только сообщение всем терминалам (chat => 'all')
 * или с 'shout' => true — с именем отправителя: «Кухня: текст». Личные и групповые туда не попадают.
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
    $video = isset($options['video']) ? trim((string)$options['video']) : '';
    $file_name = isset($options['file_name']) ? trim((string)$options['file_name']) : '';
    if ($file != '' && $file_name == '') {
        $file_name = basename($file);
    }
    if ($message == '' && $image == '' && $sound == '' && $file == '' && $video == '') {
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
    $chat = isset($options['chat']) ? preg_replace('/[^a-z0-9:_]/i', '', (string)$options['chat']) : '';
    // копия в общий чат MajorDoMo (shouts) — только сообщение всем терминалам, одна запись на отправку,
    // с именем отправителя в тексте (чат показывает автором имя компьютера)
    if ($chat == 'all' || !empty($options['shout'])) {
        $text = $message;
        if ($text == '') {
            if ($video != '') {
                $text = terminalsLang('TERMINALS_VIDEO');
            } elseif ($file != '') {
                $text = terminalsLang('TERMINALS_FILE') . ': ' . $file_name;
            } elseif ($sound != '' && $image == '') {
                $text = terminalsLang('TERMINALS_VOICE_MESSAGE');
            }
        }
        if ($sender != '') {
            $text = $sender . ': ' . $text;
        }
        $shout = array(
            // таблица shouts в utf8 (3 байта): эмодзи и другие 4-байтовые символы она не принимает
            'MESSAGE' => preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $text),
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
    $send_id = md5(uniqid('', true) . mt_rand());
    $sender_title = $sender;
    if ($sender != '' && !empty($options['chat_title'])) {
        $sender_title = $sender . ' · ' . trim((string)$options['chat_title']);
    }
    $sent = 0;
    $recipients = array();
    $from_name = (isset($from['NAME']) && !empty($from['ID'])) ? $from['NAME'] : $sender;
    foreach ((array)$to as $destination) {
        $terminal_rec = terminalsFind($destination);
        if (empty($terminal_rec['ID'])) {
            DebMes('terminalsSend: terminal not found: ' . $destination, 'terminals');
            continue;
        }
        // переписка терминалов (страница «Сообщения»): сначала запись — её номер идёт с сообщением
        // до устройства, по нему ставится «доставлено». Текст хранится в JSON, чтобы не терять эмодзи
        $rec = array(
            'FROM_NAME' => $from_name,
            'TO_NAME' => $terminal_rec['NAME'],
            'MESSAGE' => json_encode($message),
            'IMAGE' => $image,
            'SOUND' => $sound,
            'FILE' => $file,
            'VIDEO' => $video,
            'FILE_NAME' => $file_name,
            'ADDED' => time(),
            'CHAT' => $chat,
            'SEND_ID' => $send_id,
        );
        $rec['ID'] = SQLInsert('terminals_messages', $rec);
        $message_id = (int)$rec['ID'];
        $recipients[] = $terminal_rec['NAME'];
        $sent++;
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
                terminalsQueueAdd($terminal_rec, 'sound', '', $level, $sound, '', $sender_title, $message_id);
            } elseif ($tts->playFile($sound, $level, $sender_title)) {
                terminalsMessagesDelivered($message_id);
            }
        }
        // видео: окно видео на устройстве, если дополнение умеет (pipup: sendVideo по ссылке, со звуком).
        // В очередь не ставится — окно видео живёт своим сроком
        $video_shown = false;
        if ($speaks && $video != '' && is_object($tts) && method_exists($tts, 'sendVideo')) {
            $url = method_exists($tts, 'fileUrl') ? $tts->fileUrl($video) : $video;
            $duration = 60;
            if (function_exists('getMediaDurationSeconds') && file_exists(ROOT . ltrim($video, '/'))) {
                $seconds = (int)getMediaDurationSeconds(ROOT . ltrim($video, '/'));
                if ($seconds > 0) {
                    $duration = min($seconds + 3, 600);
                }
            }
            $video_shown = $url && (bool)$tts->sendVideo($url, '', $sender_title, 'video', array('muted' => false, 'duration' => $duration));
            if ($video_shown) {
                terminalsMessagesDelivered($message_id);
            }
        }
        // только файл или видео, которое устройство не покажет: на устройство — текст без озвучки
        // («Файл: имя», «Видео»), сам файл — на странице «Сообщения»
        if ($speaks && $message == '' && $image == '' && $sound == '' && ($file != '' || ($video != '' && !$video_shown))) {
            include_once(DIR_MODULES . 'terminals/terminals.class.php');
            $terminals = new terminals();
            $label = ($file != '') ? terminalsLang('TERMINALS_FILE') . ': ' . $file_name : terminalsLang('TERMINALS_VIDEO');
            $terminals->terminalSay($terminal_rec, $label, $level, '', $sender_title, $message_id);
        }
        if ($speaks && ($message != '' || $image != '')) {
            if ($voice && $message != '') {
                // как sayTo(): терминалы и генераторы речи получают событие SAYTO
                processSubscriptionsSafe('SAYTO', array('level' => $level, 'message' => $message,
                    'destination' => $terminal_rec['NAME'], 'image' => $image, 'from' => $sender_title, 'message_id' => $message_id));
            } else {
                include_once(DIR_MODULES . 'terminals/terminals.class.php');
                $terminals = new terminals();
                $terminals->terminalSay($terminal_rec, $message, $level, $image, $sender_title, $message_id);
            }
        }
    }
    // открытые страницы «Сообщения» получают одно событие на отправку (кэш вебсокета хранит
    // только последнее событие с этим именем) и сразу обновляют переписку
    if (count($recipients)) {
        terminalsMessagesNotify($from_name, $recipients);
    }
    return $sent;
}

// Событие вебсокета TERMINAL_MESSAGE для страниц «Сообщения»: FROM — кто, TO — список, кого касается
function terminalsMessagesNotify($from, $to)
{
    if (function_exists('postToWebSocketQueue')) {
        postToWebSocketQueue('TERMINAL_MESSAGE', json_encode(array('FROM' => (string)$from, 'TO' => array_values(array_unique((array)$to)),
            'TIME' => time())), 'PostEvent');
    }
}

// Сообщения доставлены (дополнение устройства приняло их или страница получателя загрузила):
// $ids — номер или список через запятую. Отправители узнают об этом через вебсокет
function terminalsMessagesDelivered($ids)
{
    $ids = implode(',', array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string)$ids))));
    if ($ids == '') {
        return;
    }
    $rows = SQLSelect("SELECT DISTINCT FROM_NAME, TO_NAME FROM terminals_messages WHERE ID IN ($ids) AND DELIVERED=0");
    if (!count($rows)) {
        return;
    }
    SQLExec("UPDATE terminals_messages SET DELIVERED=" . time() . " WHERE ID IN ($ids) AND DELIVERED=0");
    $senders = array();
    foreach ($rows as $row) {
        $senders[] = $row['FROM_NAME'];
    }
    terminalsMessagesNotify($rows[0]['TO_NAME'], $senders);
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
