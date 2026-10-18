<?php
/*
 * Страница «Сообщения» (frontend модуля terminals): обмен сообщениями между терминалами.
 * Открывается на устройстве-терминале: /module/terminals.html (страница /popup/… выводит
 * только модули app_*), на странице меню — [#module name="terminals" action="messages"#].
 *
 * Отправитель — терминал, с которого открыта страница: ядро определяет его по адресу устройства
 * или по ?terminal=ИМЯ (запоминается в сессии). Если не определился — выбирается на странице.
 * Можно отправить текст (с озвучкой на терминале-получателе или без), картинку или фото
 * и голосовое сообщение. Работает по HTTP: фото и запись голоса — через выбор файла
 * (на телефонах и планшетах он предлагает камеру и диктофон), без доступа браузера к микрофону.
 * Доставка — terminalsSend() и очередь получателя.
 *
 * Выполняется внутри terminals::usual(&$out).
 */

global $session;

$messages_dir = 'cms/cached/terminals';
$image_ext = array('jpg', 'jpeg', 'png', 'gif', 'webp');
$audio_ext = array('mp3', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'amr', '3gp', 'webm');
$max_size = 15 * 1024 * 1024;

// терминал этого устройства: выбранный на странице (from), из сессии или по адресу устройства
$me = array();
if (gr('from') != '') {
    $me = terminalsFind(gr('from'));
}
if (empty($me['ID']) && is_object($session) && !empty($session->data['TERMINAL'])) {
    $me = terminalsFind($session->data['TERMINAL']);
}
if (empty($me['ID']) && !empty($_SERVER['REMOTE_ADDR'])) {
    $list = getTerminalsByHost($_SERVER['REMOTE_ADDR'], 1);
    $me = !empty($list[0]['ID']) ? $list[0] : array();
}

// сохранить загруженный файл в cms/cached/terminals; вернуть путь от веб-корня или ''
$save_upload = function ($field, $allowed) use ($messages_dir, $max_size) {
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return '';
    }
    if ((int)$_FILES[$field]['size'] <= 0 || (int)$_FILES[$field]['size'] > $max_size) {
        return '';
    }
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return '';
    }
    $dir = ROOT . $messages_dir;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    // старые файлы сообщений (больше недели) удаляем
    foreach ((array)@scandir($dir) as $old) {
        if ($old != '.' && $old != '..' && is_file($dir . '/' . $old) && filemtime($dir . '/' . $old) < time() - 7 * 24 * 3600) {
            @unlink($dir . '/' . $old);
        }
    }
    $name = md5(uniqid('', true) . mt_rand()) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        return '';
    }
    return $messages_dir . '/' . $name;
};

// отправка
if ($this->mode == 'send_message' || gr('mode') == 'send_message') {
    $to = gr('to');
    $to = is_array($to) ? $to : (($to != '') ? array($to) : array());
    $text = gr('message');
    $image = $save_upload('image', $image_ext);
    $sound = $save_upload('audio', $audio_ext);
    $sent = 0;
    if (!empty($me['ID']) && count($to) && ($text != '' || $image != '' || $sound != '')) {
        $sent = terminalsSend($to, $text, array(
            'from' => $me['NAME'],
            'image' => $image,
            'sound' => $sound,
            'voice' => gr('voice', 'int') ? true : false,
        ));
    }
    if (gr('ajax')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('sent' => $sent));
        exit;
    }
    $out['SENT'] = $sent ? 1 : 0;
    $out['SEND_FAILED'] = $sent ? 0 : 1;
}

// переписка этого терминала: входящие и отправленные
$conversation = array();
if (!empty($me['ID'])) {
    $name = DBSafe($me['NAME']);
    $rows = SQLSelect("SELECT * FROM terminals_messages WHERE TO_NAME='$name' OR FROM_NAME='$name' ORDER BY ID DESC LIMIT 50");
    $titles = array();
    foreach (SQLSelect("SELECT NAME, TITLE FROM terminals") as $t) {
        $titles[$t['NAME']] = $t['TITLE'];
    }
    foreach ($rows as $row) {
        $text = json_decode($row['MESSAGE'], true);
        $incoming = ($row['TO_NAME'] == $me['NAME']);
        $other = $incoming ? $row['FROM_NAME'] : $row['TO_NAME'];
        $conversation[] = array(
            'id' => (int)$row['ID'],
            'incoming' => $incoming,
            'other' => $other,
            'other_title' => isset($titles[$other]) ? $titles[$other] : $other,
            'text' => is_string($text) ? $text : '',
            'image' => ($row['IMAGE'] != '' && !preg_match('/^https?:/i', $row['IMAGE'])) ? ROOTHTML . ltrim($row['IMAGE'], '/') : $row['IMAGE'],
            'sound' => ($row['SOUND'] != '' && !preg_match('/^https?:/i', $row['SOUND'])) ? ROOTHTML . ltrim($row['SOUND'], '/') : $row['SOUND'],
            'time' => date('d.m H:i', (int)$row['ADDED']),
        );
    }
}
if (gr('ajax') && gr('list')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($conversation);
    exit;
}

$out['ME_NAME'] = !empty($me['ID']) ? $me['NAME'] : '';
$out['ME_TITLE'] = !empty($me['ID']) ? $me['TITLE'] : '';
$out['MSG_TERMINALS'] = SQLSelect("SELECT NAME, TITLE FROM terminals WHERE CANTTS=1 AND TTS_TYPE!=''" . (!empty($me['ID']) ? " AND ID!=" . (int)$me['ID'] : '') . " ORDER BY TITLE");
$out['ALL_TERMINALS'] = SQLSelect("SELECT NAME, TITLE FROM terminals ORDER BY TITLE");
$out['CONVERSATION_JSON'] = json_encode($conversation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$out['MESSAGES_PAGE'] = 1;
// вебсокет MajorDoMo: мгновенное обновление переписки; без него — опрос
$out['WS_PORT'] = (defined('WEBSOCKETS_PORT') && !(defined('DISABLE_WEBSOCKETS') && DISABLE_WEBSOCKETS == 1)) ? (int)WEBSOCKETS_PORT : '';
