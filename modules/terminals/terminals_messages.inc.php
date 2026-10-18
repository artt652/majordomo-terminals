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
$video_ext = array('mp4', 'm4v', 'webm', 'mov', '3gp', 'mkv');
// файлы: только эти расширения — файлы лежат в веб-корне, исполняемые (php, html, svg…) не принимаются
$file_ext = array('pdf', 'txt', 'md', 'log', 'csv', 'json', 'rtf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'zip', '7z', 'rar', 'gz', 'tgz', 'tar', 'apk', 'epub', 'fb2', 'mp4', 'mkv', 'avi', 'mov', 'flac', 'heic', 'bmp');
$file_ext = array_merge($file_ext, $image_ext, $audio_ext, $video_ext);
// предел размера: свой (картинки и звук 15 МБ, видео и файлы 50 МБ), но не больше настроек PHP
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
// страница открыта — терминал в сети: отметить активность, как shoutbox и command.php (не чаще раза в минуту)
if (!empty($me['ID']) && (empty($me['LATEST_ACTIVITY']) || strtotime($me['LATEST_ACTIVITY']) < time() - 60)) {
    $activity = array('ID' => (int)$me['ID'], 'LATEST_ACTIVITY' => date('Y-m-d H:i:s'), 'IS_ONLINE' => 1);
    SQLUpdate('terminals', $activity);
}
foreach (SQLSelect("SELECT ID, NAME, TITLE, LOCATION_ID, LATEST_ACTIVITY, IS_ONLINE FROM terminals ORDER BY TITLE") as $t) {
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

// пользователь MajorDoMo, вошедший на сайт с этого устройства: от его имени пишется в общий чат
$user_id = (is_object($session) && !empty($session->data['SITE_USER_ID'])) ? (int)$session->data['SITE_USER_ID'] : 0;
// имя компьютера — автор сообщений дома в общем чате (как в shoutbox)
$computer_name = (defined('SETTINGS_GENERAL_ALICE_NAME') && SETTINGS_GENERAL_ALICE_NAME != '') ? SETTINGS_GENERAL_ALICE_NAME
    : (defined('LANG_DEFAULT_COMPUTER_NAME') ? LANG_DEFAULT_COMPUTER_NAME : 'MajorDoMo');

// чаты этого терминала: key, type (all|house|shoutroom|room|group|term), title, members;
// readonly — писать нельзя (не выполнен вход), text_only — только текст
$chats = array();
if (count($all_terminals) > 1 || $me_name == '') {
    $chats[] = array('key' => 'all', 'type' => 'all', 'title' => terminalsLang('TERMINALS_ALL'), 'members' => array_keys($all_terminals));
}
// общий чат MajorDoMo (shoutbox): сообщение — команда дому, как из чата (say от пользователя),
// ответ дома приходит сюда же и звучит на этом терминале
$chats[] = array('key' => 'house', 'type' => 'house', 'title' => $computer_name, 'members' => array(),
    'readonly' => ($user_id && $me_name != '') ? 0 : 1, 'text_only' => 1);
// публичные комнаты чата MajorDoMo (shoutrooms): переписка людей, без команд и озвучки
$shout_rooms = array();
$has_rooms = SQLSelectOne("SHOW TABLES LIKE 'shoutrooms'");
if (!empty($has_rooms)) {
    $shout_rooms = SQLSelect("SELECT shoutrooms.ID, shoutrooms.TITLE, shoutrooms.ADDED, users.NAME AS USER_NAME FROM shoutrooms"
        . " LEFT JOIN users ON users.ID=shoutrooms.ADDED_BY WHERE shoutrooms.IS_PUBLIC=1 ORDER BY shoutrooms.PRIORITY DESC, shoutrooms.TITLE");
}
foreach ($shout_rooms as $room) {
    // кто и когда создал комнату — для карточки
    $created = (!empty($room['ADDED']) && strtotime($room['ADDED']) > 0) ? date('d.m.Y', strtotime($room['ADDED'])) : '';
    $chats[] = array('key' => 'shout:' . $room['ID'], 'type' => 'shoutroom', 'title' => $room['TITLE'], 'members' => array(),
        'readonly' => ($user_id && $me_name != '') ? 0 : 1, 'text_only' => 1,
        'created_by' => (string)$room['USER_NAME'], 'created' => $created);
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
        $chats[] = array('key' => 'group:' . $group['ID'], 'type' => 'group', 'title' => $group['TITLE'], 'members' => $members,
            'note' => (isset($group['NOTE']) && is_string(json_decode($group['NOTE'], true))) ? json_decode($group['NOTE'], true) : '');
    }
}
// личные чаты со всеми терминалами
foreach ($all_terminals as $name => $t) {
    if ($name != $me_name) {
        $chats[] = array('key' => 'term:' . $name, 'type' => 'term', 'title' => $t['TITLE'], 'members' => array($name));
    }
}
// свои цвета аватарок (общие для всех устройств): ключ чата => #rrggbb
$colors = array();
foreach (SQLSelect("SELECT CHAT_KEY, COLOR FROM terminals_colors") as $row) {
    $colors[$row['CHAT_KEY']] = $row['COLOR'];
}
foreach ($chats as $i => $chat) {
    $chats[$i]['color'] = isset($colors[$chat['key']]) ? $colors[$chat['key']] : '';
}
// статус терминалов: в сети, на связи, последняя активность
$statuses = array();
foreach ($all_terminals as $name => $t) {
    $statuses[$name] = terminalsStatus($t);
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

// цвет аватарки чата (терминала, комнаты, группы…) — общий для всех устройств; пусто — по умолчанию
if (gr('mode') == 'set_color') {
    $key = (string)gr('chat');
    $color = strtolower(trim((string)gr('color')));
    $ok = 0;
    if ($find_chat($key) || $key == 'term:' . $me_name) {
        SQLExec("DELETE FROM terminals_colors WHERE CHAT_KEY='" . DBSafe($key) . "'");
        if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $row = array('CHAT_KEY' => $key, 'COLOR' => $color);
            SQLInsert('terminals_colors', $row);
        }
        $ok = 1;
    }
    $json_out(array('ok' => $ok));
}

// карточка терминала: возможности и текущие настройки (только просмотр)
if (gr('ajax') && gr('info') != '') {
    $terminal_rec = terminalsFind(gr('info'));
    $json_out(!empty($terminal_rec['ID']) ? terminalsInfo($terminal_rec) : array());
}

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
        // своя заметка к группе (до 1000 символов); в JSON — чтобы эмодзи не терялись в utf8-таблице
        $note = trim((string)gr('note'));
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 1000, 'UTF-8');
        }
        $group['NOTE'] = ($note != '') ? json_encode($note) : '';
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
    // общий чат и его комнаты: только текст, от пользователя, вошедшего на сайт
    if (!empty($chat['key']) && ($chat['type'] == 'house' || $chat['type'] == 'shoutroom')) {
        $sent = 0;
        $text = trim((string)$text);
        if ($text != '' && $user_id && $me_name != '') {
            // shouts в utf8: 4-байтовые символы (эмодзи) таблица не принимает
            $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $text);
            if ($chat['type'] == 'house') {
                // как shoutbox и command.php: отметить запрос терминала — по нему sayReply() находит,
                // куда отвечать, — и отдать команду дому от пользователя (source terminalID)
                $now = date('Y-m-d H:i:s');
                $activity = array('ID' => (int)$me['ID'], 'LATEST_ACTIVITY' => $now, 'LATEST_REQUEST_TIME' => $now,
                    'LATEST_REQUEST' => htmlspecialchars($text), 'IS_ONLINE' => 1);
                SQLUpdate('terminals', $activity);
                say(htmlspecialchars($text), 0, $user_id, 'terminal' . $me['ID']);
            } else {
                $shout = array('MESSAGE' => htmlspecialchars($text), 'IMAGE' => '', 'ADDED' => date('Y-m-d H:i:s'),
                    'ROOM_ID' => (int)substr($chat['key'], 6), 'MEMBER_ID' => $user_id, 'SOURCE' => 'terminal' . $me['ID']);
                SQLInsert('shouts', $shout);
            }
            $sent = 1;
        }
        $json_out(array('sent' => $sent, 'error' => $user_id ? '' : 'login'));
    }
    $image = $save_upload('image', $image_ext, $max_media);
    if ($image == '') {
        // снимок с камеры (отдельный пункт меню)
        $image = $save_upload('image_camera', $image_ext, $max_media);
    }
    $sound = $save_upload('audio', $audio_ext, $max_media);
    $file = $save_upload('file', $file_ext, $max_file);
    $video = $save_upload('video', $video_ext, $max_file);
    if ($video == '') {
        // видео с камеры (отдельный пункт меню)
        $video = $save_upload('video_camera', $video_ext, $max_file);
    }
    $sent = 0;
    if ($upload_error == '' && $me_name != '' && count($to) && ($text != '' || $image != '' || $sound != '' || $file != '' || $video != '')) {
        $options = array(
            'from' => $me_name,
            'image' => $image,
            'sound' => $sound,
            'file' => $file,
            'video' => $video,
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

// прочитано: получатель открыл чат на своей странице — его входящие в этом чате прочитаны (и доставлены)
if (gr('mode') == 'mark_read' && $me_name != '') {
    $key = (string)gr('chat');
    $cond = '';
    if (strpos($key, 'term:') === 0) {
        $cond = "CHAT='' AND FROM_NAME='" . DBSafe(substr($key, 5)) . "'";
    } elseif (preg_match('/^(all|room:\d+|group:\d+)$/', $key)) {
        $cond = "CHAT='" . DBSafe($key) . "'";
    }
    $count = 0;
    if ($cond != '') {
        $rows = SQLSelect("SELECT ID, FROM_NAME FROM terminals_messages WHERE TO_NAME='" . DBSafe($me_name) . "' AND READ_AT=0 AND $cond");
        if (count($rows)) {
            $ids = array();
            $senders = array();
            foreach ($rows as $row) {
                $ids[] = (int)$row['ID'];
                $senders[] = $row['FROM_NAME'];
            }
            $now = time();
            SQLExec("UPDATE terminals_messages SET READ_AT=$now, DELIVERED=IF(DELIVERED=0, $now, DELIVERED) WHERE ID IN (" . implode(',', $ids) . ")");
            terminalsMessagesNotify($me_name, $senders);
            $count = count($ids);
        }
    }
    $json_out(array('ok' => 1, 'read' => $count));
}

// доставлено: страница получателя загрузила переписку
if ($me_name != '') {
    $rows = SQLSelect("SELECT ID FROM terminals_messages WHERE TO_NAME='" . DBSafe($me_name) . "' AND DELIVERED=0");
    if (count($rows)) {
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int)$row['ID'];
        }
        terminalsMessagesDelivered($ids);
    }
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
        // состояние отправленного: 1 — отправлено, 2 — доставлено, 3 — прочитано;
        // в группе — лучшее по всем получателям (как в мессенджерах: прочитал хотя бы один)
        $state = !empty($row['READ_AT']) ? 3 : (!empty($row['DELIVERED']) ? 2 : 1);
        if (isset($seen[$uniq])) {
            if (!$incoming && $state > $conversation[$seen[$uniq]]['status']) {
                $conversation[$seen[$uniq]]['status'] = $state;
            }
            continue;
        }
        $seen[$uniq] = count($conversation);
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
            'video' => (!empty($row['VIDEO']) && !preg_match('/^https?:/i', $row['VIDEO'])) ? ROOTHTML . ltrim($row['VIDEO'], '/') : (isset($row['VIDEO']) ? $row['VIDEO'] : ''),
            'file' => (!empty($row['FILE']) && !preg_match('/^https?:/i', $row['FILE'])) ? ROOTHTML . ltrim($row['FILE'], '/') : (isset($row['FILE']) ? $row['FILE'] : ''),
            'file_name' => isset($row['FILE_NAME']) ? $row['FILE_NAME'] : '',
            'time' => date('d.m H:i', (int)$row['ADDED']),
            'ts' => (int)$row['ADDED'],
            'status' => $incoming ? 0 : $state,
            'read' => ($incoming && !empty($row['READ_AT'])) ? 1 : 0,
        );
    }
}
// общий чат MajorDoMo и публичные комнаты: последние записи shouts.
// Свои — записанные с этого терминала (source terminalID) или его копии «всем» (source «terminal: Название»)
$shout_rows = array();
$shout_chats = array(0 => 'house');
foreach ($shout_rooms as $room) {
    $shout_chats[(int)$room['ID']] = 'shout:' . $room['ID'];
}
foreach ($shout_chats as $room_id => $chat_key) {
    $rows = SQLSelect("SELECT shouts.*, users.NAME AS USER_NAME, users.COLOR AS USER_COLOR FROM shouts"
        . " LEFT JOIN users ON shouts.MEMBER_ID=users.ID WHERE shouts.ROOM_ID=" . (int)$room_id . " ORDER BY shouts.ID DESC LIMIT 50");
    foreach ($rows as $row) {
        $source = (string)$row['SOURCE'];
        $text = html_entity_decode((string)$row['MESSAGE'], ENT_QUOTES, 'UTF-8');
        $author = ((int)$row['MEMBER_ID'] && $row['USER_NAME'] != '') ? $row['USER_NAME'] : $computer_name;
        $color = ((int)$row['MEMBER_ID'] && trim((string)$row['USER_COLOR']) != '') ? trim($row['USER_COLOR']) : '';
        // копия сообщения терминала «всем»: автор — терминал, имя из начала текста убираем
        if (preg_match('/^terminal: (.+)$/u', $source, $m)) {
            $author = $m[1];
            if (strpos($text, $author . ': ') === 0) {
                $text = substr($text, strlen($author . ': '));
            }
        } elseif (preg_match('/^terminal(\d+)$/', $source, $m) && (int)$row['MEMBER_ID']) {
            // написано с терминала: «Пользователь (Терминал)»
            foreach ($all_terminals as $t) {
                if ((int)$t['ID'] == (int)$m[1]) {
                    $author .= ' (' . $t['TITLE'] . ')';
                }
            }
        }
        $mine = !empty($me['ID']) && ($source == 'terminal' . $me['ID'] || $source == 'terminal: ' . $me['TITLE']);
        $image = '';
        if ($row['IMAGE'] != '') {
            $path = (string)$row['IMAGE'];
            if (strpos($path, ROOT) === 0) {
                $path = substr($path, strlen(ROOT));
            }
            if (preg_match('/^https?:/i', $path)) {
                $image = $path;
            } elseif (file_exists(ROOT . ltrim($path, '/'))) {
                $image = ROOTHTML . ltrim($path, '/');
            }
        }
        $ts = strtotime($row['ADDED']);
        $shout_rows[] = array(
            'id' => (int)$row['ID'],
            'chat' => $chat_key,
            'incoming' => !$mine,
            'other' => '',
            'other_title' => $author,
            'color' => $color,
            'text' => $text,
            'image' => $image,
            'sound' => '', 'video' => '', 'file' => '', 'file_name' => '',
            'time' => date('d.m H:i', $ts),
            'ts' => $ts,
        );
    }
}
$conversation = array_merge($conversation, $shout_rows);

if (gr('ajax') && gr('list')) {
    $json_out(array('messages' => $conversation, 'chats' => $chats, 'status' => $statuses, 'colors' => $colors));
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
$out['STATUS_JSON'] = json_encode((object)$statuses, $flags);
$out['COLORS_JSON'] = json_encode((object)$colors, $flags);
// кто вошёл на сайт с этого устройства — для карточки общего чата
$user_name = '';
if ($user_id) {
    $user = SQLSelectOne("SELECT NAME, USERNAME FROM users WHERE ID=" . (int)$user_id);
    $user_name = !empty($user['NAME']) ? $user['NAME'] : (isset($user['USERNAME']) ? $user['USERNAME'] : '');
}
$out['USER_NAME_JSON'] = json_encode($user_name, $flags);
$out['MESSAGES_PAGE'] = 1;
// пределы размера для проверки в браузере до отправки (МБ)
$out['MAX_MEDIA_MB'] = round($max_media / 1048576, 1);
$out['MAX_FILE_MB'] = round($max_file / 1048576, 1);
// вебсокет MajorDoMo: мгновенное обновление переписки; без него — опрос
$out['WS_PORT'] = (defined('WEBSOCKETS_PORT') && !(defined('DISABLE_WEBSOCKETS') && DISABLE_WEBSOCKETS == 1)) ? (int)WEBSOCKETS_PORT : '';
