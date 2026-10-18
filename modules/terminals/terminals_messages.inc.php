<?php
/*
 * Страница «Сообщения» (frontend модуля terminals): обмен сообщениями между терминалами.
 * Открывается на устройстве-терминале как приложение app_messages: /popup/app_messages.html
 * (или /module/terminals.html), на странице меню — [#module name="terminals" action="messages"#].
 *
 * Отправитель — терминал, с которого открыта страница: ядро определяет его по адресу устройства
 * или по ?terminal=ИМЯ (запоминается в сессии). Если не определился — выбирается на странице.
 * Чаты как в мессенджере: «Все терминалы», комнаты MajorDoMo (терминал, привязанный к комнате,
 * сам состоит в её чате), свои группы терминалов и личные чаты с каждым терминалом.
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
// файлы: только эти расширения — файлы лежат в веб-корне, исполняемые (php, html, svg…) не принимаются
$file_ext = array('pdf', 'txt', 'md', 'log', 'csv', 'json', 'rtf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'zip', '7z', 'rar', 'gz', 'tgz', 'tar', 'apk', 'epub', 'fb2', 'mp4', 'mkv', 'avi', 'mov', 'flac', 'heic', 'bmp');
$file_ext = array_merge($file_ext, $image_ext, $audio_ext);
// предел размера: свой (картинки и звук 15 МБ, файлы 50 МБ), но не больше настроек PHP
$ini_bytes = function ($value) {
    $value = trim((string)$value);
    $n = (float)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
};
$php_limit = 0;
foreach (array('upload_max_filesize', 'post_max_size') as $ini) {
    $bytes = $ini_bytes(ini_get($ini));
    if ($bytes > 0 && ($php_limit == 0 || $bytes < $php_limit)) {
        $php_limit = $bytes;
    }
}
$limit = function ($own) use ($php_limit) {
    return ($php_limit > 0) ? min($own, $php_limit) : $own;
};
$max_media = $limit(15 * 1024 * 1024);
$max_file = $limit(50 * 1024 * 1024);
$upload_error = '';

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
// (ошибка — в $upload_error: too_big, type, upload)
$save_upload = function ($field, $allowed, $max_size) use ($messages_dir, &$upload_error) {
    if (empty($_FILES[$field]) || (isset($_FILES[$field]['error']) && $_FILES[$field]['error'] == UPLOAD_ERR_NO_FILE)) {
        return '';
    }
    $error = isset($_FILES[$field]['error']) ? (int)$_FILES[$field]['error'] : UPLOAD_ERR_OK;
    if ($error == UPLOAD_ERR_INI_SIZE || $error == UPLOAD_ERR_FORM_SIZE) {
        $upload_error = 'too_big';
        return '';
    }
    if ($error != UPLOAD_ERR_OK || empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $upload_error = 'upload';
        return '';
    }
    if ((int)$_FILES[$field]['size'] <= 0 || (int)$_FILES[$field]['size'] > $max_size) {
        $upload_error = 'too_big';
        return '';
    }
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        $upload_error = 'type';
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
        $upload_error = 'upload';
        return '';
    }
    return $messages_dir . '/' . $name;
};
// имя файла для показа: без пути и управляющих символов, не длиннее 120 символов
$upload_name = function ($field) {
    $name = isset($_FILES[$field]['name']) ? (string)$_FILES[$field]['name'] : '';
    $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '', basename(str_replace('\\', '/', $name)));
    if (function_exists('mb_substr') && mb_strlen($name, 'UTF-8') > 120) {
        $name = mb_substr($name, 0, 120, 'UTF-8');
    }
    return $name;
};

// все терминалы: имя => запись
$all_terminals = array();
foreach (SQLSelect("SELECT ID, NAME, TITLE, LOCATION_ID FROM terminals ORDER BY TITLE") as $t) {
    $all_terminals[$t['NAME']] = $t;
}
$me_name = !empty($me['ID']) ? $me['NAME'] : '';

// своя группа: участники — список имён терминалов (JSON); удалённые терминалы пропускаем
$group_members = function ($group) use ($all_terminals) {
    $members = json_decode((string)$group['MEMBERS'], true);
    $result = array();
    foreach ((array)$members as $name) {
        if (is_string($name) && isset($all_terminals[$name]) && !in_array($name, $result, true)) {
            $result[] = $name;
        }
    }
    return $result;
};

// чаты этого терминала: key, type (all|room|group|term), title, members
$chats = array();
if (count($all_terminals) > 1 || $me_name == '') {
    $chats[] = array('key' => 'all', 'type' => 'all', 'title' => terminalsLang('TERMINALS_ALL'), 'members' => array_keys($all_terminals));
}
// комнаты, к которым привязаны терминалы: участники — терминалы комнаты
$rooms = SQLSelect("SELECT ID, TITLE FROM locations ORDER BY PRIORITY DESC, TITLE");
foreach ($rooms as $room) {
    $members = array();
    foreach ($all_terminals as $name => $t) {
        if ((int)$t['LOCATION_ID'] == (int)$room['ID']) {
            $members[] = $name;
        }
    }
    // комната, где только этот терминал, не показывается — в ней некому писать
    if (count(array_diff($members, array($me_name)))) {
        $chats[] = array('key' => 'room:' . $room['ID'], 'type' => 'room', 'title' => $room['TITLE'], 'members' => $members);
    }
}
// свои группы: видны участникам (если терминал не определился — все)
foreach (SQLSelect("SELECT * FROM terminals_groups ORDER BY TITLE") as $group) {
    $members = $group_members($group);
    if ($me_name == '' || in_array($me_name, $members, true)) {
        $chats[] = array('key' => 'group:' . $group['ID'], 'type' => 'group', 'title' => $group['TITLE'], 'members' => $members);
    }
}
// личные чаты со всеми терминалами
foreach ($all_terminals as $name => $t) {
    if ($name != $me_name) {
        $chats[] = array('key' => 'term:' . $name, 'type' => 'term', 'title' => $t['TITLE'], 'members' => array($name));
    }
}
$find_chat = function ($key) use (&$chats) {
    foreach ($chats as $chat) {
        if ($chat['key'] === $key) {
            return $chat;
        }
    }
    return array();
};

$json_out = function ($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
};

// своя группа: создать или изменить (название, участники); этот терминал всегда в группе
if (gr('mode') == 'group_save' && $me_name != '') {
    $id = gr('id', 'int');
    $title = trim((string)gr('title'));
    $members = gr('members');
    $members = is_array($members) ? $members : array();
    $members[] = $me_name;
    $list = array();
    foreach ($members as $name) {
        if (is_string($name) && isset($all_terminals[$name]) && !in_array($name, $list, true)) {
            $list[] = $name;
        }
    }
    $result = array('ok' => 0);
    if ($title != '' && count($list) > 1) {
        $group = $id ? SQLSelectOne("SELECT * FROM terminals_groups WHERE ID=" . (int)$id) : array();
        if ($id && (empty($group['ID']) || !in_array($me_name, $group_members($group), true))) {
            $json_out($result);
        }
        $group['TITLE'] = $title;
        $group['MEMBERS'] = json_encode($list);
        if (!empty($group['ID'])) {
            SQLUpdate('terminals_groups', $group);
        } else {
            $group['ADDED'] = time();
            $group['ID'] = SQLInsert('terminals_groups', $group);
        }
        $result = array('ok' => 1, 'key' => 'group:' . $group['ID']);
    }
    $json_out($result);
}

// своя группа: удалить (может любой участник); её сообщения больше не показываются
if (gr('mode') == 'group_delete' && $me_name != '') {
    $group = SQLSelectOne("SELECT * FROM terminals_groups WHERE ID=" . gr('id', 'int'));
    $ok = 0;
    if (!empty($group['ID']) && in_array($me_name, $group_members($group), true)) {
        SQLExec("DELETE FROM terminals_groups WHERE ID=" . (int)$group['ID']);
        $ok = 1;
    }
    $json_out(array('ok' => $ok));
}

// отправка: в чат (chat=all|room:ID|group:ID|term:ИМЯ — получатели все участники, кроме себя)
// или по списку to[] (как раньше)
if ($this->mode == 'send_message' || gr('mode') == 'send_message') {
    $chat_key = (string)gr('chat');
    $chat = ($chat_key != '') ? $find_chat($chat_key) : array();
    if (!empty($chat['key'])) {
        $to = array_values(array_diff($chat['members'], array($me_name)));
    } else {
        $to = gr('to');
        $to = is_array($to) ? $to : (($to != '') ? array($to) : array());
    }
    $text = gr('message');
    $image = $save_upload('image', $image_ext, $max_media);
    if ($image == '') {
        // снимок с камеры (отдельный пункт меню)
        $image = $save_upload('image_camera', $image_ext, $max_media);
    }
    $sound = $save_upload('audio', $audio_ext, $max_media);
    $file = $save_upload('file', $file_ext, $max_file);
    $sent = 0;
    if ($upload_error == '' && $me_name != '' && count($to) && ($text != '' || $image != '' || $sound != '' || $file != '')) {
        $options = array(
            'from' => $me_name,
            'image' => $image,
            'sound' => $sound,
            'file' => $file,
            'file_name' => ($file != '') ? $upload_name('file') : '',
            'voice' => gr('voice', 'int') ? true : false,
        );
        // групповой чат: на странице сообщение попадёт в этот чат, на устройстве — «Отправитель · Чат»
        if (!empty($chat['key']) && $chat['type'] != 'term') {
            $options['chat'] = $chat['key'];
            $options['chat_title'] = $chat['title'];
        }
        $sent = terminalsSend($to, $text, $options);
    }
    if (gr('ajax')) {
        $json_out(array('sent' => $sent, 'error' => $upload_error));
    }
    $out['SENT'] = $sent ? 1 : 0;
    $out['SEND_FAILED'] = $sent ? 0 : 1;
}

// переписка этого терминала: входящие и отправленные. Чат сообщения — групповой (all, room:, group:)
// или личный (term:собеседник). Отправка в группу — по строке на получателя: показываем одну (SEND_ID)
$conversation = array();
if ($me_name != '') {
    $name = DBSafe($me_name);
    $rows = SQLSelect("SELECT * FROM terminals_messages WHERE TO_NAME='$name' OR FROM_NAME='$name' ORDER BY ID DESC LIMIT 300");
    $seen = array();
    foreach ($rows as $row) {
        $incoming = ($row['TO_NAME'] == $me_name);
        $other = $incoming ? $row['FROM_NAME'] : $row['TO_NAME'];
        $chat_key = (isset($row['CHAT']) && $row['CHAT'] != '') ? $row['CHAT'] : 'term:' . $other;
        $send_id = (isset($row['SEND_ID']) && $row['SEND_ID'] != '') ? $row['SEND_ID'] : 'id' . $row['ID'];
        $uniq = $chat_key . '|' . $send_id . '|' . ($incoming ? 'in' : 'out');
        if (isset($seen[$uniq])) {
            continue;
        }
        $seen[$uniq] = 1;
        $text = json_decode($row['MESSAGE'], true);
        $conversation[] = array(
            'id' => (int)$row['ID'],
            'chat' => $chat_key,
            'incoming' => $incoming,
            'other' => $other,
            'other_title' => isset($all_terminals[$other]) ? $all_terminals[$other]['TITLE'] : $other,
            'text' => is_string($text) ? $text : '',
            'image' => ($row['IMAGE'] != '' && !preg_match('/^https?:/i', $row['IMAGE'])) ? ROOTHTML . ltrim($row['IMAGE'], '/') : $row['IMAGE'],
            'sound' => ($row['SOUND'] != '' && !preg_match('/^https?:/i', $row['SOUND'])) ? ROOTHTML . ltrim($row['SOUND'], '/') : $row['SOUND'],
            'file' => (!empty($row['FILE']) && !preg_match('/^https?:/i', $row['FILE'])) ? ROOTHTML . ltrim($row['FILE'], '/') : (isset($row['FILE']) ? $row['FILE'] : ''),
            'file_name' => isset($row['FILE_NAME']) ? $row['FILE_NAME'] : '',
            'time' => date('d.m H:i', (int)$row['ADDED']),
            'ts' => (int)$row['ADDED'],
        );
    }
}
if (gr('ajax') && gr('list')) {
    $json_out(array('messages' => $conversation, 'chats' => $chats));
}

$out['ME_NAME'] = $me_name;
$out['ME_TITLE'] = !empty($me['ID']) ? $me['TITLE'] : '';
$out['ALL_TERMINALS'] = array_values($all_terminals);
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$out['CONVERSATION_JSON'] = json_encode($conversation, $flags);
$out['CHATS_JSON'] = json_encode($chats, $flags);
$titles = array();
foreach ($all_terminals as $name => $t) {
    $titles[$name] = $t['TITLE'];
}
$out['TITLES_JSON'] = json_encode((object)$titles, $flags);
$out['MESSAGES_PAGE'] = 1;
// пределы размера для проверки в браузере до отправки (МБ)
$out['MAX_MEDIA_MB'] = round($max_media / 1048576, 1);
$out['MAX_FILE_MB'] = round($max_file / 1048576, 1);
// вебсокет MajorDoMo: мгновенное обновление переписки; без него — опрос
$out['WS_PORT'] = (defined('WEBSOCKETS_PORT') && !(defined('DISABLE_WEBSOCKETS') && DISABLE_WEBSOCKETS == 1)) ? (int)WEBSOCKETS_PORT : '';
