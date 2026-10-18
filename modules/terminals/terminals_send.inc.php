<?php
/*
 * Сообщение на терминал (или несколько) — из сценариев и из карточки терминала.
 *
 *   include_once(DIR_MODULES . 'terminals/terminals.class.php');
 *   terminalsSend('Кухня', 'Ужин готов', array(
 *       'from'  => 'Гостиная',                  // от кого: терминал (имя или название) или любой текст
 *       'image' => 'cms/cached/snapshot.jpg',   // картинка: путь в веб-корне MajorDoMo или http-ссылка
 *       'sound' => 'cms/sounds/dingdong.mp3',   // звук перед сообщением: путь в веб-корне или http-ссылка
 *       'voice' => true,                        // озвучить текст генератором речи (по умолчанию да)
 *       'level' => 5,                           // уровень; по умолчанию — порог терминала-получателя
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
    if ($message == '' && $image == '' && $sound == '') {
        return 0;
    }
    // отправитель: название терминала, если это терминал, иначе как есть
    $sender = isset($options['from']) ? trim((string)$options['from']) : '';
    if ($sender != '') {
        $from = terminalsFind($sender);
        if (!empty($from['ID'])) {
            $sender = $from['TITLE'];
        }
    }
    // история сообщений — одна запись на отправку
    if ($message != '') {
        $shout = array(
            'MESSAGE' => $message,
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
    $sent = 0;
    foreach ((array)$to as $destination) {
        $terminal_rec = terminalsFind($destination);
        if (empty($terminal_rec['ID']) || !$terminal_rec['CANTTS'] || $terminal_rec['TTS_TYPE'] == '') {
            DebMes('terminalsSend: terminal not found or cannot speak: ' . $destination, 'terminals');
            continue;
        }
        $level = isset($options['level']) ? (int)$options['level'] : terminalsMinLevel($terminal_rec);
        DebMes('terminalsSend to ' . $terminal_rec['TITLE'] . ($sender != '' ? " from $sender" : '') . " (level $level): $message"
            . ($image != '' ? " (image: $image)" : '') . ($sound != '' ? " (sound: $sound)" : ''), 'terminals');
        $tts = terminalsQueueAddon($terminal_rec);
        // звук перед сообщением
        if ($sound != '' && is_object($tts) && method_exists($tts, 'playFile')) {
            if (terminalsQueueUsed($tts)) {
                terminalsQueueAdd($terminal_rec, 'sound', '', $level, $sound, '', $sender);
            } else {
                $tts->playFile($sound, $level, $sender);
            }
        }
        if ($message != '' || $image != '') {
            if ($voice && $message != '') {
                // как sayTo(): терминалы и генераторы речи получают событие SAYTO
                processSubscriptionsSafe('SAYTO', array('level' => $level, 'message' => $message,
                    'destination' => $terminal_rec['NAME'], 'image' => $image, 'from' => $sender));
            } else {
                include_once(DIR_MODULES . 'terminals/terminals.class.php');
                $terminals = new terminals();
                $terminals->terminalSay($terminal_rec, $message, $level, $image, $sender);
            }
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
