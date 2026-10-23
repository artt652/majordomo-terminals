<?php
/*
 * Страница «Сообщения» (frontend модуля terminals): обмен сообщениями между терминалами.
 * Открывается на устройстве-терминале как приложение app_messages: /popup/app_messages.html
 * (или /module/terminals.html), на странице меню — [#module name="terminals" action="messages"#].
 *
 * Отправитель — терминал, с которого открыта страница (по ключу, из панели управления или как прежде), и (или)
 * пользователь MajorDoMo (ключ пользователя или вход на сайт); без терминала — от пользователя (ниже).
 * Чаты как в мессенджере: «Все терминалы», комнаты MajorDoMo (терминал, привязанный к комнате,
 * сам состоит в её чате), свои группы терминалов и личные чаты с каждым терминалом.
 * Можно отправить текст (с озвучкой на терминале-получателе или без), фото, видео, музыку, файл
 * и голосовое сообщение (запись в браузере по HTTPS или на localhost, иначе — выбор файла записи).
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

// Кто открыл страницу: терминал этого устройства и (или) пользователь MajorDoMo.
// Терминал:
// 1) Ключ (terminals_auth.inc.php): ?token=… (ссылка из карточки терминала, API) или cookie tmmsg_key —
//    страница открывается как терминал ключа, с любого адреса; адрес, сессия, ?terminal= и from= не смотрятся.
// 2) Вход в панель управления в этом браузере (AUTHORIZED в общей сессии ядра, modules/panel.class.php) —
//    любой терминал (выбор под стрелкой у имени): по умолчанию узнанный, как ниже, иначе MAIN.
// 3) Как прежде: выбранный на странице (from), из сессии (ядро пишет в неё ?terminal=) или по адресу
//    устройства; не узнан — выбирается на странице. Терминал с галочкой «Только по ключу» так не открывается
//    и в выборе его нет — это закрывает подмену по адресу (обратный прокси), сессии и ?terminal=.
// Затем «Контроль доступа» (правила безопасности) своего терминала: не пускает — страница закрыта.
// Пользователь — пишет от своего имени:
// 1) ключ пользователя (настройки «Сообщений»): ?token=… или cookie tmmsg_user;
// 2) вход на сайт MajorDoMo в этом браузере (SITE_USER_ID) — если у пользователя есть пароль
//    или открыта панель управления (без пароля ядро входит пользователем само, это ничего не доказывает).
// Пользователь узнан, а терминал — нет (или он «Только по ключу», или закрыт правилами): страница открывается
// от пользователя, без терминала — его личные чаты, группы, комнаты, чаты MajorDoMo.
$me = array();
$me_user = array();
$by_key = false;
$user_by_key = false;
$as_admin = false;
$lock = '';
$url_key = strtolower(trim((string)gr('token')));
$terminal_cookie = !empty($_COOKIE['tmmsg_key']) ? (string)$_COOKIE['tmmsg_key'] : '';
$user_cookie = !empty($_COOKIE['tmmsg_user']) ? (string)$_COOKIE['tmmsg_user'] : '';
if ($url_key != '') {
    // ссылка с ключом терминала или пользователя: ключ — в свою cookie
    $me = terminalsTokenFind($url_key);
    if (!empty($me['ID'])) {
        $by_key = true;
        terminalsTokenCookie($url_key);
    } else {
        $me_user = terminalsUserTokenFind($url_key);
        if (!empty($me_user['ID'])) {
            $user_by_key = true;
            terminalsTokenCookie($url_key, 'tmmsg_user');
        } else {
            $lock = 'bad_key';
        }
    }
    // страница по ссылке с ключом: ключ — в cookie, из адресной строки и истории — убрать
    if ($lock == '' && !gr('ajax') && gr('mode') == '' && !headers_sent()) {
        $query = $_GET;
        unset($query['token']);
        $uri = strtok((string)$_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $uri . (count($query) ? '?' . http_build_query($query) : ''));
        exit;
    }
}
// ключ в cookie больше не действует (пересоздан, терминал или пользователь удалён): убрать, дальше — как без ключа
if (!$by_key && $lock == '' && $terminal_cookie != '') {
    $me = terminalsTokenFind($terminal_cookie);
    if (!empty($me['ID'])) {
        $by_key = true;
    } else {
        terminalsTokenCookie('');
    }
}
if (!$user_by_key && $lock == '' && $user_cookie != '') {
    $me_user = terminalsUserTokenFind($user_cookie);
    if (!empty($me_user['ID'])) {
        $user_by_key = true;
    } else {
        terminalsTokenCookie('', 'tmmsg_user');
    }
}
// вход на сайт пользователем без пароля не принят — для пояснения на закрытой странице
$site_user_no_password = '';
if (empty($me_user['ID']) && $lock == '' && is_object($session) && !empty($session->data['SITE_USER_ID'])) {
    $site_user = SQLSelectOne("SELECT * FROM users WHERE ID=" . (int)$session->data['SITE_USER_ID']);
    if (!empty($site_user['ID']) && (terminalsUserHasPassword($site_user) || !empty($session->data['AUTHORIZED']))) {
        $me_user = $site_user;
    } elseif (!empty($site_user['ID'])) {
        $site_user_no_password = ($site_user['NAME'] != '') ? $site_user['NAME'] : $site_user['USERNAME'];
    }
}
if (!$by_key && $lock == '') {
    // как прежде: from, сессия, адрес устройства
    $found = array();
    if (gr('from') != '') {
        $found = terminalsFind(gr('from'));
    }
    if (empty($found['ID']) && is_object($session) && !empty($session->data['TERMINAL'])) {
        $found = terminalsFind($session->data['TERMINAL']);
    }
    if (empty($found['ID']) && !empty($_SERVER['REMOTE_ADDR'])) {
        $list = getTerminalsByHost($_SERVER['REMOTE_ADDR'], 1);
        $found = !empty($list[0]['ID']) ? $list[0] : array();
    }
    if (is_object($session) && !empty($session->data['AUTHORIZED'])) {
        // панель управления: любой терминал, по умолчанию узнанный, иначе MAIN
        $as_admin = true;
        $me = $found;
        if (empty($me['ID'])) {
            $list = getTerminalsByName('MAIN', 1);
            $me = !empty($list[0]['ID']) ? $list[0] : SQLSelectOne("SELECT * FROM terminals ORDER BY ID LIMIT 1");
        }
    } elseif (!empty($found['ID']) && terminalsTokenRequired($found['ID'])) {
        // без ключа этот терминал не открывается: пользователь — без терминала, иначе страница закрыта
        if (empty($me_user['ID'])) {
            $lock = 'need_key';
        }
    } else {
        $me = $found;
    }
}
// Правила безопасности ядра смотрят терминал и пользователя в сессии: на время запроса в ней — эти.
// Вход по ключу терминала записывается в сессию насовсем (правила ядра на этом устройстве видят проверенный
// терминал); иначе сессия в конце запроса возвращается как была. Ключ пользователя на сайт не входит:
// пользователь в сессии — только на время запроса
$session_saved = array();
foreach (array('TERMINAL', 'SITE_USER_ID', 'SITE_USERNAME') as $k) {
    $session_saved[$k] = (is_object($session) && isset($session->data[$k])) ? $session->data[$k] : null;
}
$restore_user = function () use (&$session, $session_saved) {
    foreach (array('SITE_USER_ID', 'SITE_USERNAME') as $k) {
        if ($session_saved[$k] === null) {
            unset($session->data[$k]);
        } else {
            $session->data[$k] = $session_saved[$k];
        }
    }
};
$finish_session = function () use (&$session, &$by_key, &$user_by_key, $session_saved, $restore_user) {
    if (!is_object($session)) {
        return;
    }
    if (!$by_key) {
        if ($session_saved['TERMINAL'] === null) {
            unset($session->data['TERMINAL'], $session->data['TERMINAL_ID']);
        } else {
            $session->data['TERMINAL'] = $session_saved['TERMINAL'];
        }
    }
    if ($user_by_key) {
        $restore_user();
    }
    if (method_exists($session, 'save')) {
        $session->save();
    }
};
if ($user_by_key && is_object($session)) {
    $session->data['SITE_USER_ID'] = (int)$me_user['ID'];
    $session->data['SITE_USERNAME'] = $me_user['USERNAME'];
}
if (!empty($me['ID']) && is_object($session)) {
    $session->data['TERMINAL'] = $me['NAME'];
    unset($session->data['TERMINAL_ID']);
    if (function_exists('checkAccess') && !checkAccess('terminal', $me['ID'])) {
        // терминал закрыт правилами: пользователь — без терминала, иначе страница закрыта
        $me = array();
        $by_key = false;
        $as_admin = false;
        if (empty($me_user['ID'])) {
            $lock = 'rules';
        }
    }
}
// выход с устройства (who=user — пользователя): ключ из cookie — убрать; дальше страница узнаёт как без ключа
if (gr('mode') == 'logout') {
    if (gr('who') == 'user') {
        terminalsTokenCookie('', 'tmmsg_user');
    } else {
        terminalsTokenCookie('');
        $by_key = false;
    }
    $finish_session();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => 1));
    exit;
}
// страница закрыта: API получает ошибку, страница — только пояснение, без чатов и переписки
if ($lock != '') {
    $finish_session();
    if (gr('ajax')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('sent' => 0, 'ok' => 0, 'error' => ($lock == 'rules') ? 'rules' : 'key'));
        exit;
    }
    $out['LOCK'] = $lock;
    $out['LOCK_USER_NOTE'] = ($lock == 'need_key' && $site_user_no_password != '')
        ? sprintf(terminalsLang('TERMINALS_LOCK_NO_PASSWORD'), htmlspecialchars($site_user_no_password, ENT_QUOTES, 'UTF-8')) : '';
    $out['ME_NAME'] = '';
    $out['ME_TITLE'] = '';
    $out['ALL_TERMINALS'] = array();
    foreach (array('CONVERSATION_JSON', 'CHATS_JSON') as $k) {
        $out[$k] = '[]';
    }
    foreach (array('TITLES_JSON', 'STATUS_JSON', 'COLORS_JSON', 'EMOJIS_JSON', 'MORE_JSON', 'AVATARS_JSON') as $k) {
        $out[$k] = '{}';
    }
    $out['USER_NAME_JSON'] = '""';
    $out['ME_USER_KEY'] = '';
    $out['USER_BY_KEY'] = 0;
    $out['WS_EXTERNAL_JSON'] = '""';
    $out['WS_PORT'] = '';
    $out['MAX_MEDIA_MB'] = 15;
    $out['MAX_FILE_MB'] = 50;
    return;
}

// Запросы страницы (ajax, mode) — короткие, но некоторые ждут сеть (снимки камер, доставка устройствам).
// Файл сессии ядра заперт до конца запроса, и остальные запросы этой страницы (обновление, снимки,
// отправка) стояли бы в очереди за ним. Сессию сохраняем и закрываем сразу; в памяти её данные остаются
// до конца запроса (правила безопасности смотрят их)
if (gr('ajax') || gr('mode') != '') {
    $session_now = is_object($session) ? $session->data : null;
    $finish_session();
    if (function_exists('session_write_close') && session_id()) {
        session_write_close();
    }
    if (is_object($session)) {
        $session->data = $session_now;
    }
}
// Ответ браузеру сейчас, а работа (доставка устройствам) — дальше, уже без него: отправка не ждёт
// медленные или выключенные устройства. Content-Length и Connection: close — браузер получает ответ
// целиком; под PHP-FPM — fastcgi_finish_request()
$respond_now = function ($data) {
    ignore_user_abort(true);
    $json = json_encode($data);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        header('Content-Encoding: none');
    }
    echo $json;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
};
// после записи сообщения в переписку — ответить сразу (terminalsSend, опция after_record)
$responded = false;
$answer_after_record = function ($extra = array()) use (&$responded, $respond_now) {
    return function ($sent) use (&$responded, $respond_now, $extra) {
        if ($sent > 0 && !$responded) {
            $responded = true;
            $respond_now(array_merge(array('sent' => $sent, 'error' => ''), $extra));
        }
    };
};

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
    // старые файлы удаляются вместе с сообщениями (terminalsMessagesCleanup, раз в сутки)
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
// администратор смотрит страницу от имени другого терминала (не MAIN): терминал не отмечается в сети,
// его входящие не помечаются доставленными и прочитанными
$peek = $as_admin && !empty($me['NAME']) && strcasecmp($me['NAME'], 'MAIN') != 0;
// страница открыта — терминал в сети: отметить активность, как shoutbox и command.php (не чаще раза в минуту)
if (!$peek && !empty($me['ID']) && (empty($me['LATEST_ACTIVITY']) || strtotime($me['LATEST_ACTIVITY']) < time() - 60)) {
    $activity = array('ID' => (int)$me['ID'], 'LATEST_ACTIVITY' => date('Y-m-d H:i:s'), 'IS_ONLINE' => 1);
    SQLUpdate('terminals', $activity);
}
foreach (SQLSelect("SELECT ID, NAME, TITLE, LOCATION_ID, LATEST_ACTIVITY, IS_ONLINE FROM terminals ORDER BY TITLE") as $t) {
    // «Контроль доступа» терминала-получателя: не пускает этот терминал (пользователя, время) — его не видно,
    // ни в личных чатах, ни в составе комнат и групп, и сообщения от этого терминала ему не уходят
    if (!empty($me['ID']) && $t['NAME'] != $me['NAME'] && function_exists('checkAccess') && !checkAccess('terminal', $t['ID'])) {
        continue;
    }
    $all_terminals[$t['NAME']] = $t;
}
$me_name = !empty($me['ID']) ? $me['NAME'] : '';
// переписка хранит ID терминалов (FROM_ID, TO_ID): после переименования история остаётся; имена — текущие.
// HIDE_TO / HIDE_FROM — получатель / отправитель очистил историю у себя
$me_id = !empty($me['ID']) ? (int)$me['ID'] : 0;
$id_names = array();
foreach (SQLSelect("SELECT ID, NAME FROM terminals") as $t) {
    $id_names[(int)$t['ID']] = $t['NAME'];
}
// пользователь MajorDoMo (по ключу или вход на сайт, см. выше): пишет от своего имени (как в общем чате);
// его личные сообщения (TO_USER) видны на любой странице, где он узнан
$user_id = !empty($me_user['ID']) ? (int)$me_user['ID'] : 0;
// пользователи MajorDoMo: ID => запись (имя, цвет, аватар) — личные чаты и участники групп (user:ID)
$all_users = array();
foreach (SQLSelect("SELECT ID, NAME, USERNAME, COLOR, AVATAR FROM users ORDER BY NAME") as $u) {
    $all_users[(int)$u['ID']] = $u;
}
if (!isset($all_users[$user_id])) {
    $user_id = 0;
}
$me_user_key = $user_id ? 'user:' . $user_id : '';
// строки, адресованные мне (терминалу или пользователю) и отправленные мной; нет ни того, ни другого — ничьи
$to_me = array();
$from_me = array();
if ($me_id) {
    $to_me[] = "TO_ID=$me_id";
    $from_me[] = "(FROM_ID=$me_id AND FROM_USER=0)";
}
if ($user_id) {
    $to_me[] = "TO_USER=$user_id";
    $from_me[] = "FROM_USER=$user_id";
}
$to_me_sql = count($to_me) ? '(' . implode(' OR ', $to_me) . ')' : '0';
$from_me_sql = count($from_me) ? '(' . implode(' OR ', $from_me) . ')' : '0';
// страницу открыл кто-то: терминал или пользователь без терминала — можно писать и читать переписку
$can_write = ($me_name != '' || $user_id);
// свои ключи участника групп: терминал и пользователь
$me_keys = array_values(array_filter(array($me_name, $me_user_key)));
$is_member = function ($members) use ($me_keys) {
    return count(array_intersect($me_keys, $members)) > 0;
};
$mine_sql = "(($to_me_sql AND HIDE_TO=0) OR ($from_me_sql AND HIDE_FROM=0))";
$term_id = function ($name) use ($all_terminals) {
    return isset($all_terminals[$name]) ? (int)$all_terminals[$name]['ID'] : 0;
};
// собеседник личного чата: условие на поля FROM_* / TO_* (term:ИМЯ, user:ID, system)
$party_sql = function ($key, $side) use ($term_id) {
    if (strpos($key, 'user:') === 0) {
        return "{$side}_USER=" . (int)substr($key, 5);
    }
    if ($key == 'system') {
        return "{$side}_ID=0 AND {$side}_USER=0";
    }
    return "{$side}_ID=" . $term_id(substr($key, 5)) . " AND {$side}_USER=0";
};

// своя группа: участники — имена терминалов и пользователи (user:ID), JSON; удалённых пропускаем
$member_ok = function ($name) use ($all_terminals, $all_users) {
    return is_string($name) && (isset($all_terminals[$name]) || (strpos($name, 'user:') === 0 && isset($all_users[(int)substr($name, 5)])));
};
$group_members = function ($group) use ($member_ok) {
    $members = json_decode((string)$group['MEMBERS'], true);
    $result = array();
    foreach ((array)$members as $name) {
        if ($member_ok($name) && !in_array($name, $result, true)) {
            $result[] = $name;
        }
    }
    return $result;
};
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
    'readonly' => $user_id ? 0 : 1, 'text_only' => 1);
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
        'readonly' => $user_id ? 0 : 1, 'text_only' => 1,
        'created_by' => (string)$room['USER_NAME'], 'created' => $created);
}
// «Камеры» (go2rtc, terminals_go2rtc.inc.php): потоки, видимые этому терминалу или пользователю (права —
// в настройках «Сообщений»; администратор видит все). Чат — пульт: сетка снимков, просмотр, показ на терминалах,
// снимок в чат, ссылка для внешнего просмотра (WebTorrent)
$cam_admin = $as_admin || (!empty($me_user['IS_ADMIN']));
$my_cameras = ($can_write && terminalsGo2rtcEnabled()) ? terminalsCamerasFor($me_name, $user_id, $cam_admin) : array();
if (count($my_cameras)) {
    $go2rtc_cfg = terminalsGo2rtcConfig();
    $shares = terminalsGo2rtcShares();
    $cameras = array();
    foreach ($my_cameras as $name) {
        $cameras[] = array('name' => $name, 'shared' => isset($shares[$name]) ? (int)$shares[$name] : 0);
    }
    $chats[] = array('key' => 'cameras', 'type' => 'cameras', 'title' => terminalsLang('TERMINALS_CAMERAS'), 'members' => array(),
        'readonly' => 1, 'cameras' => $cameras, 'webtorrent' => $go2rtc_cfg['webtorrent'] ? 1 : 0);
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
    // все комнаты MajorDoMo; в комнате без других терминалов писать некому — чат только для чтения
    $empty = count(array_diff($members, array($me_name))) ? 0 : 1;
    $chats[] = array('key' => 'room:' . $room['ID'], 'type' => 'room', 'title' => $room['TITLE'], 'members' => $members,
        'readonly' => $empty, 'empty' => $empty);
}
// свои группы: видны участникам (терминалу или пользователю; если никто не узнан — все)
foreach (SQLSelect("SELECT * FROM terminals_groups ORDER BY TITLE") as $group) {
    $members = $group_members($group);
    if (!$can_write || $is_member($members)) {
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
// личные чаты с пользователями MajorDoMo (кроме себя)
foreach ($all_users as $uid => $u) {
    if ($uid != $user_id) {
        $chats[] = array('key' => 'user:' . $uid, 'type' => 'user', 'title' => $u['NAME'] != '' ? $u['NAME'] : $u['USERNAME'],
            'members' => array('user:' . $uid), 'username' => (string)$u['USERNAME']);
    }
}
// «Система»: личные сообщения не от терминала (сценарий, карточка терминала без отправителя или с текстом
// вместо терминала) — только чтение; есть, только если такие сообщения есть
if ($can_write) {
    $system = SQLSelectOne("SELECT ID FROM terminals_messages WHERE CHAT='' AND $to_me_sql AND FROM_ID=0 AND FROM_USER=0 AND HIDE_TO=0 LIMIT 1");
    if (!empty($system['ID'])) {
        $chats[] = array('key' => 'system', 'type' => 'system', 'title' => terminalsLang('TERMINALS_SYSTEM_CHAT'), 'members' => array(),
            'readonly' => 1, 'text_only' => 1);
    }
}
// свои аватарки (общие для всех устройств): ключ чата => цвет #rrggbb и эмодзи.
// Эмодзи хранится в JSON (\uXXXX): таблицы в utf8 4-байтовые символы не принимают
$colors = array();
$emojis = array();
foreach (SQLSelect("SELECT * FROM terminals_colors") as $row) {
    if ($row['COLOR'] != '') {
        $colors[$row['CHAT_KEY']] = $row['COLOR'];
    }
    $emoji = (isset($row['EMOJI']) && $row['EMOJI'] != '') ? json_decode($row['EMOJI'], true) : '';
    if (is_string($emoji) && $emoji != '') {
        $emojis[$row['CHAT_KEY']] = $emoji;
    }
}
// пользователи: цвет из MajorDoMo, если свой не выбран; аватар — картинка из cms/avatars
foreach ($all_users as $uid => $u) {
    if (!isset($colors['user:' . $uid]) && preg_match('/^#[0-9a-f]{6}$/i', trim((string)$u['COLOR']))) {
        $colors['user:' . $uid] = trim($u['COLOR']);
    }
}
foreach ($chats as $i => $chat) {
    $chats[$i]['color'] = isset($colors[$chat['key']]) ? $colors[$chat['key']] : '';
}
$user_avatars = array();
foreach ($all_users as $uid => $u) {
    if ($u['AVATAR'] != '' && is_file(ROOT . 'cms/avatars/' . basename($u['AVATAR']))) {
        $user_avatars['user:' . $uid] = ROOTHTML . 'cms/avatars/' . rawurlencode(basename($u['AVATAR']));
    }
}
// эмодзи-аватарка — у терминалов, пользователей, комнат, своих групп и чатов MajorDoMo (у «Все терминалы» — свой значок)
$emoji_allowed = function ($key) {
    return (bool)preg_match('/^(term:.+|user:\d+|room:\d+|group:\d+|house|shout:\d+)$/', $key);
};
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

$json_out = function ($data) use ($finish_session) {
    $finish_session();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
};

// аватарка чата: цвет и/или эмодзи — меняется то, что передано (пусто — по умолчанию)
if (gr('mode') == 'set_color') {
    $key = (string)gr('chat');
    $ok = 0;
    if ($find_chat($key) || $key == 'term:' . $me_name || ($me_user_key != '' && $key == $me_user_key)) {
        $row = SQLSelectOne("SELECT * FROM terminals_colors WHERE CHAT_KEY='" . DBSafe($key) . "'");
        if (empty($row['ID'])) {
            $row = array('CHAT_KEY' => $key, 'COLOR' => '', 'EMOJI' => '');
        }
        if (isset($_REQUEST['color'])) {
            $color = strtolower(trim((string)gr('color')));
            $row['COLOR'] = preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '';
        }
        if (isset($_REQUEST['emoji']) && $emoji_allowed($key)) {
            // одна эмодзи (с модификаторами): без букв, цифр и разметки, не длиннее 8 символов
            $emoji = trim((string)gr('emoji'));
            $valid = $emoji != '' && !preg_match('/[\p{L}\p{N}<>&"\'\s]/u', $emoji)
                && (!function_exists('mb_strlen') || mb_strlen($emoji, 'UTF-8') <= 8);
            if ($valid) {
                $row['EMOJI'] = json_encode($emoji);
            } elseif ($emoji == '') {
                $row['EMOJI'] = '';
            } else {
                // не эмодзи — ничего не меняем
                $json_out(array('ok' => 0));
            }
        }
        if ($row['COLOR'] == '' && $row['EMOJI'] == '') {
            SQLExec("DELETE FROM terminals_colors WHERE CHAT_KEY='" . DBSafe($key) . "'");
        } elseif (!empty($row['ID'])) {
            SQLUpdate('terminals_colors', $row);
        } else {
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

// своя группа: создать или изменить (название, участники); этот терминал (без терминала — пользователь)
// всегда в группе
if (gr('mode') == 'group_save' && $can_write) {
    $id = gr('id', 'int');
    $title = trim((string)gr('title'));
    $members = gr('members');
    $members = is_array($members) ? $members : array();
    $members[] = ($me_name != '') ? $me_name : $me_user_key;
    $list = array();
    foreach ($members as $name) {
        if ($member_ok($name) && !in_array($name, $list, true)) {
            $list[] = $name;
        }
    }
    $result = array('ok' => 0);
    if ($title != '' && count($list) > 1) {
        $group = $id ? SQLSelectOne("SELECT * FROM terminals_groups WHERE ID=" . (int)$id) : array();
        if ($id && (empty($group['ID']) || !$is_member($group_members($group)))) {
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
if (gr('mode') == 'group_delete' && $can_write) {
    $group = SQLSelectOne("SELECT * FROM terminals_groups WHERE ID=" . gr('id', 'int'));
    $ok = 0;
    if (!empty($group['ID']) && $is_member($group_members($group))) {
        SQLExec("DELETE FROM terminals_groups WHERE ID=" . (int)$group['ID']);
        $ok = 1;
    }
    $json_out(array('ok' => $ok));
}

// общий чат MajorDoMo и его комнаты: только текст, от пользователя. Возвращает 1 — записано.
// Без терминала — как shoutbox без терминала (source «terminal»): ответ дома — по правилам ядра (sayReply)
$send_to_shouts = function ($chat, $text) use ($user_id, $me, $me_name, &$session, $peek, $by_key, $user_by_key, $restore_user) {
    $text = trim((string)$text);
    if ($text == '' || !$user_id) {
        return 0;
    }
    // shouts в utf8: 4-байтовые символы (эмодзи) таблица не принимает
    $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $text);
    $source = 'terminal' . ($me_name != '' ? $me['ID'] : '');
    if ($chat['type'] == 'house' && $me_name == '') {
        say(htmlspecialchars($text), 0, $user_id, $source);
    } elseif ($chat['type'] == 'house') {
        // как shoutbox и command.php: отметить запрос терминала — по нему sayReply() находит,
        // куда отвечать, — и отдать команду дому от пользователя (source terminalID)
        $now = date('Y-m-d H:i:s');
        $activity = array('ID' => (int)$me['ID'], 'LATEST_ACTIVITY' => $now, 'LATEST_REQUEST_TIME' => $now,
            'LATEST_REQUEST' => htmlspecialchars($text), 'IS_ONLINE' => 1);
        if ($peek) {
            // из панели от имени другого терминала: ответ дома — ему, но «в сети» он не отмечается
            unset($activity['LATEST_ACTIVITY'], $activity['IS_ONLINE']);
        }
        SQLUpdate('terminals', $activity);
        // правила шаблонов поведения смотрят терминал в сессии, а событие COMMAND получает эту сессию:
        // в ней должен быть этот терминал, сохранённый до say(). Только при входе по ключу: сессия
        // закрывается до say() и вернуть её уже нельзя — из панели управления терминал остался бы
        // в сессии насовсем (ядро отмечало бы его «в сети»); команда из панели идёт с сессией панели
        if ($by_key && is_object($session)) {
            $session->data['TERMINAL'] = $me['NAME'];
            unset($session->data['TERMINAL_ID']);
            if ($user_by_key) {
                // ключ пользователя на сайт не входит: в сохраняемой сессии — прежний пользователь
                $restore_user();
            }
            if (method_exists($session, 'save')) {
                $session->save();
            }
            if (function_exists('session_write_close') && session_id()) {
                session_write_close();
            }
        }
        say(htmlspecialchars($text), 0, $user_id, $source);
    } else {
        $shout = array('MESSAGE' => htmlspecialchars($text), 'IMAGE' => '', 'ADDED' => date('Y-m-d H:i:s'),
            'ROOM_ID' => (int)substr($chat['key'], 6), 'MEMBER_ID' => $user_id, 'SOURCE' => $source);
        SQLInsert('shouts', $shout);
    }
    return 1;
};

// терминалам чата (или списку $to): текст и вложения ($media — image, sound, file, file_name, video),
// $forwarded — от кого пересланное сообщение. Возвращает число получателей
$send_to_terminals = function ($chat, $to, $text, $media, $voice, $forwarded = '', $after_record = null) use ($me_name, $user_id) {
    $media = array_merge(array('image' => '', 'sound' => '', 'file' => '', 'file_name' => '', 'video' => '',
        'sound_duration' => 0, 'video_duration' => 0), (array)$media);
    if (($me_name == '' && !$user_id) || !count($to) || ($text == '' && $media['image'] == '' && $media['sound'] == '' && $media['file'] == '' && $media['video'] == '')) {
        return 0;
    }
    $options = array(
        'from' => $me_name,
        'image' => $media['image'],
        'sound' => $media['sound'],
        'file' => $media['file'],
        'video' => $media['video'],
        'file_name' => $media['file_name'],
        'sound_duration' => $media['sound_duration'],
        'video_duration' => $media['video_duration'],
        'voice' => $voice ? true : false,
        'forwarded' => $forwarded,
    );
    // вошедший на сайт пишет от своего имени (на устройстве — его имя в заголовке)
    if ($user_id) {
        $options['from_user'] = $user_id;
    }
    if ($after_record) {
        $options['after_record'] = $after_record;
    }
    // групповой чат: на странице сообщение попадёт в этот чат, на устройстве — «Отправитель · Чат»
    if (!empty($chat['key']) && $chat['type'] != 'term' && $chat['type'] != 'user') {
        $options['chat'] = $chat['key'];
        $options['chat_title'] = $chat['title'];
    }
    return terminalsSend($to, $text, $options);
};

// Камеры. Снимок для страницы (через сервер: браузеру не нужен доступ к go2rtc и его пароль)
if (gr('mode') == 'cam_frame') {
    $name = (string)gr('name');
    $jpeg = in_array($name, $my_cameras, true) ? terminalsGo2rtcFrame($name, min(1280, max(0, gr('w', 'int')))) : '';
    $finish_session();
    if ($jpeg == '') {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    echo $jpeg;
    exit;
}
// WebRTC: согласование (WHEP — предложение SDP, ответ go2rtc) через сервер — для браузера и устройств (PiPup,
// ссылка с ключом терминала). Само видео идёт с go2rtc напрямую; пароль API go2rtc остаётся на сервере
if (gr('mode') == 'whep') {
    $name = (string)gr('src');
    $finish_session();
    // камера разрешена этому терминалу или пользователю — или показана ему (подписанная ссылка, terminalsGo2rtcUrl)
    $signed = $by_key && !empty($me['ID']) && (int)gr('exp') > time()
        && hash_equals(terminalsGo2rtcSign($name, $me['ID'], (int)gr('exp')), (string)gr('sig'));
    if ((!in_array($name, $my_cameras, true) && !($signed && terminalsGo2rtcHas($name))) || $_SERVER['REQUEST_METHOD'] != 'POST') {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
    $type_in = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : 'application/sdp';
    $type_in = (stripos($type_in, 'json') !== false) ? 'application/json' : 'application/sdp';
    // камера недавно не ответила — сразу отказ (браузер покажет снимок «нет связи», PHP не ждёт)
    if (terminalsGo2rtcDown($name)) {
        header('HTTP/1.1 504 Gateway Timeout');
        exit;
    }
    $answer = terminalsGo2rtcRequest('POST', 'api/webrtc', array('src' => $name), (string)file_get_contents('php://input'),
        $type_in, $code, 8, $type_out);
    header('HTTP/1.1 ' . (($code >= 200 && $code < 300) ? $code . ' OK' : '502 Bad Gateway'));
    header('Content-Type: ' . (($type_out != '') ? $type_out : $type_in));
    echo $answer;
    exit;
}
// показать камеру на терминалах чата (chat) на duration секунд; в чат — сообщение со снимком.
// Терминал, которому эта камера не разрешена, её не получает (кроме входа администратора)
if (gr('mode') == 'cam_show' || gr('mode') == 'cam_photo') {
    $name = (string)gr('name');
    $chat = $find_chat((string)gr('chat'));
    if (!in_array($name, $my_cameras, true) || empty($chat['key']) || !empty($chat['readonly'])) {
        $json_out(array('sent' => 0, 'error' => 'not_found'));
    }
    $to = array();
    foreach (array_values(array_diff($chat['members'], array($me_name, $me_user_key))) as $member) {
        $is_user = strpos($member, 'user:') === 0;
        if ($cam_admin || terminalsCameraAllowed($name, $is_user ? '' : $member, $is_user ? (int)substr($member, 5) : 0, false)) {
            $to[] = $member;
        }
    }
    if (!count($to)) {
        $json_out(array('sent' => 0, 'error' => 'no_access'));
    }
    $options = array('from' => $me_name, 'voice' => false);
    if ($user_id) {
        $options['from_user'] = $user_id;
    }
    if ($chat['type'] != 'term' && $chat['type'] != 'user') {
        $options['chat'] = $chat['key'];
        $options['chat_title'] = $chat['title'];
    }
    $options['after_record'] = $answer_after_record(array('chat' => $chat['key']));
    if (gr('mode') == 'cam_show') {
        $options['stream'] = $name;
        $options['stream_duration'] = max(10, min(600, gr('duration', 'int') ? gr('duration', 'int') : 60));
        $sent = terminalsSend($to, '', $options);
    } else {
        $snapshot = terminalsGo2rtcSnapshot($name);
        $options['image'] = $snapshot;
        $sent = ($snapshot != '') ? terminalsSend($to, '', $options) : 0;
    }
    if ($responded) {
        exit;
    }
    $json_out(array('sent' => $sent, 'error' => $sent ? '' : 'camera', 'chat' => $chat['key']));
}
// ссылка для внешнего просмотра (WebTorrent) — создать или продлить; закрыть
if (gr('mode') == 'cam_share' || gr('mode') == 'cam_unshare') {
    $name = (string)gr('name');
    if (!in_array($name, $my_cameras, true)) {
        $json_out(array('ok' => 0));
    }
    if (gr('mode') == 'cam_unshare') {
        $json_out(array('ok' => terminalsGo2rtcUnshare($name) ? 1 : 0));
    }
    $share = terminalsGo2rtcShare($name);
    $json_out(!empty($share['url']) ? array('ok' => 1, 'url' => $share['url'], 'expires' => $share['expires']) : array('ok' => 0));
}

// отправка: в чат (chat=all|room:ID|group:ID|term:ИМЯ — получатели все участники, кроме себя)
if ($this->mode == 'send_message' || gr('mode') == 'send_message') {
    $chat_key = (string)gr('chat');
    $chat = ($chat_key != '') ? $find_chat($chat_key) : array();
    $to = !empty($chat['key']) ? array_values(array_diff($chat['members'], array($me_name, $me_user_key))) : array();
    $text = gr('message');
    if (!empty($chat['key']) && ($chat['type'] == 'house' || $chat['type'] == 'shoutroom')) {
        $json_out(array('sent' => $send_to_shouts($chat, $text), 'error' => $user_id ? '' : 'login'));
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
    // превью видео — кадр, снятый браузером отправителя (JPEG до 2 МБ): лежит рядом с видео, «имя видео.jpg».
    // В переписке показывается оно, а само видео грузится по нажатию
    if ($video != '' && !empty($_FILES['video_poster']['tmp_name']) && is_uploaded_file($_FILES['video_poster']['tmp_name'])
        && (int)$_FILES['video_poster']['size'] > 0 && (int)$_FILES['video_poster']['size'] <= 2 * 1024 * 1024) {
        $info = @getimagesize($_FILES['video_poster']['tmp_name']);
        if (is_array($info) && isset($info[2]) && $info[2] == IMAGETYPE_JPEG) {
            @move_uploaded_file($_FILES['video_poster']['tmp_name'], ROOT . $video . '.jpg');
        }
    }
    $sent = 0;
    if ($upload_error == '') {
        // длительность звука и видео — от браузера (знает её при выборе файла и при записи голосового;
        // в записях из браузера её часто нет в самом файле), не больше 10 минут
        $browser_seconds = function ($name) {
            $value = (float)str_replace(',', '.', (string)gr($name));
            return ($value > 0 && $value <= 600) ? round($value, 2) : 0;
        };
        $sent = $send_to_terminals($chat, $to, $text, array('image' => $image, 'sound' => $sound, 'file' => $file,
            'file_name' => ($file != '') ? $upload_name('file') : '', 'video' => $video,
            'sound_duration' => ($sound != '') ? $browser_seconds('audio_duration') : 0,
            'video_duration' => ($video != '') ? $browser_seconds('video_duration') : 0), gr('voice', 'int'), '', $answer_after_record());
    }
    if ($responded) {
        $finish_session();
        exit;
    }
    $json_out(array('sent' => $sent, 'error' => $upload_error));
}

// переслать сообщение в другой чат: kind=msg (переписка терминалов, только своя) или shout (общий чат
// и публичные комнаты); те же текст и вложения, «Переслано от …». В общий чат — только текст
if (gr('mode') == 'forward' && $can_write) {
    $chat = $find_chat((string)gr('chat'));
    $id = gr('id', 'int');
    $src = array();
    if (gr('kind') == 'shout') {
        $row = SQLSelectOne("SELECT shouts.*, users.NAME AS USER_NAME FROM shouts LEFT JOIN users ON shouts.MEMBER_ID=users.ID WHERE shouts.ID=$id");
        $public = !empty($row['ID']) && ((int)$row['ROOM_ID'] == 0 || SQLSelectOne("SELECT ID FROM shoutrooms WHERE IS_PUBLIC=1 AND ID=" . (int)$row['ROOM_ID']));
        if ($public) {
            $text = html_entity_decode((string)$row['MESSAGE'], ENT_QUOTES, 'UTF-8');
            $author = ((int)$row['MEMBER_ID'] && $row['USER_NAME'] != '') ? $row['USER_NAME'] : $computer_name;
            if (preg_match('/^terminal: (.+)$/u', (string)$row['SOURCE'], $m)) {
                $author = $m[1];
                if (strpos($text, $author . ': ') === 0) {
                    $text = substr($text, strlen($author . ': '));
                }
            }
            $image = (string)$row['IMAGE'];
            if ($image != '' && strpos($image, ROOT) === 0) {
                $image = substr($image, strlen(ROOT));
            }
            $src = array('text' => $text, 'media' => array('image' => ($image != '' && file_exists(ROOT . ltrim($image, '/'))) ? $image : ''), 'from' => $author);
        }
    } else {
        $row = SQLSelectOne("SELECT * FROM terminals_messages WHERE ID=$id AND $mine_sql");
        if (!empty($row['ID'])) {
            $text = json_decode($row['MESSAGE'], true);
            $from_name = isset($id_names[(int)$row['FROM_ID']]) ? $id_names[(int)$row['FROM_ID']] : $row['FROM_NAME'];
            $from = (isset($row['FORWARDED']) && $row['FORWARDED'] != '') ? $row['FORWARDED']
                : (isset($all_terminals[$from_name]) ? $all_terminals[$from_name]['TITLE'] : $from_name);
            $src = array('text' => is_string($text) ? $text : '', 'from' => $from, 'media' => array('image' => $row['IMAGE'], 'sound' => $row['SOUND'],
                'file' => isset($row['FILE']) ? $row['FILE'] : '', 'file_name' => isset($row['FILE_NAME']) ? $row['FILE_NAME'] : '',
                'video' => isset($row['VIDEO']) ? $row['VIDEO'] : ''));
        }
    }
    $sent = 0;
    $error = '';
    if (empty($src) || empty($chat['key'])) {
        $error = 'not_found';
    } elseif ($chat['type'] == 'house' || $chat['type'] == 'shoutroom') {
        $has_media = count(array_filter($src['media'])) > 0;
        if ($has_media || trim($src['text']) == '') {
            $error = 'text_only';
        } elseif (!$user_id) {
            $error = 'login';
        } else {
            $sent = $send_to_shouts($chat, $src['text']);
        }
    } else {
        $sent = $send_to_terminals($chat, array_values(array_diff($chat['members'], array($me_name, $me_user_key))), $src['text'], $src['media'],
            gr('voice', 'int'), $src['from'], $answer_after_record(array('chat' => $chat['key'])));
    }
    if ($responded) {
        exit;
    }
    $json_out(array('sent' => $sent, 'error' => $error, 'chat' => !empty($chat['key']) ? $chat['key'] : ''));
}

// прочитано: получатель открыл чат на своей странице — его входящие в этом чате прочитаны (и доставлены)
if (gr('mode') == 'mark_read' && $can_write && $peek) {
    $json_out(array('ok' => 1, 'read' => 0));
}
if (gr('mode') == 'mark_read' && $can_write) {
    $key = (string)gr('chat');
    $cond = '';
    if (strpos($key, 'term:') === 0 || strpos($key, 'user:') === 0 || $key == 'system') {
        $cond = "CHAT='' AND " . $party_sql($key, 'FROM');
    } elseif (preg_match('/^(all|room:\d+|group:\d+)$/', $key)) {
        $cond = "CHAT='" . DBSafe($key) . "'";
    }
    $count = 0;
    if ($cond != '') {
        $rows = SQLSelect("SELECT ID, FROM_ID, FROM_USER, FROM_NAME FROM terminals_messages WHERE $to_me_sql AND READ_AT=0 AND $cond");
        if (count($rows)) {
            $ids = array();
            $senders = array();
            foreach ($rows as $row) {
                $ids[] = (int)$row['ID'];
                $senders[] = (int)$row['FROM_USER'] ? 'user:' . (int)$row['FROM_USER']
                    : (isset($id_names[(int)$row['FROM_ID']]) ? $id_names[(int)$row['FROM_ID']] : $row['FROM_NAME']);
            }
            $now = time();
            SQLExec("UPDATE terminals_messages SET READ_AT=$now, DELIVERED=IF(DELIVERED=0, $now, DELIVERED) WHERE ID IN (" . implode(',', $ids) . ")");
            terminalsMessagesNotify($user_id ? $me_user_key : $me_name, $senders);
            $count = count($ids);
        }
    }
    $json_out(array('ok' => 1, 'read' => $count));
}

// доставлено: страница получателя загрузила переписку
if ($can_write && !$peek) {
    $rows = SQLSelect("SELECT ID FROM terminals_messages WHERE $to_me_sql AND DELIVERED=0");
    if (count($rows)) {
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int)$row['ID'];
        }
        terminalsMessagesDelivered($ids);
    }
}

// переписка этого терминала: входящие и отправленные. Чат сообщения — групповой (all, room:, group:)
// или личный (term:собеседник). Отправка в группу — по строке на получателя: показываем одну (SEND_ID).
// Сначала — последние $page_first сообщений всех чатов; «Показать раньше» (older=1, chat, before) —
// ещё по $page_older сообщений одного чата. min_id — самый ранний номер строки сообщения (для before)
$page_first = 500;
$page_older = 100;
// размер картинки (ширина и высота) — страница заранее отводит ей место: переписка не прыгает, пока картинки
// догружаются. Только файлы веб-корня (getimagesize читает заголовок файла); не узнали — пусто
$image_size = function ($path) {
    static $cache = array();
    $path = (string)$path;
    if ($path == '' || preg_match('/^https?:/i', $path)) {
        return array();
    }
    if (strpos($path, ROOT) === 0) {
        $path = substr($path, strlen(ROOT));
    }
    $file = ROOT . ltrim($path, '/');
    if (!isset($cache[$file])) {
        $info = is_file($file) ? @getimagesize($file) : false;
        $cache[$file] = (is_array($info) && $info[0] > 0 && $info[1] > 0) ? array((int)$info[0], (int)$info[1]) : array();
    }
    return $cache[$file];
};
$build_messages = function ($rows, $limit) use ($me_id, $user_id, $id_names, $all_terminals, $all_users, $computer_name, $image_size) {
    $conversation = array();
    $seen = array();
    foreach ($rows as $row) {
        // входящее — адресовано этому терминалу или вошедшему пользователю (а не отправлено ими)
        $from_me = ((int)$row['FROM_USER'] && (int)$row['FROM_USER'] == $user_id) || (!(int)$row['FROM_USER'] && (int)$row['FROM_ID'] == $me_id && $me_id);
        $incoming = !$from_me;
        // собеседник: пользователь (user:ID), терминал (имя) или никто — сценарий («Система»)
        $side = $incoming ? 'FROM' : 'TO';
        $other_user = (int)$row[$side . '_USER'];
        $other_id = (int)$row[$side . '_ID'];
        if ($other_user) {
            $other = 'user:' . $other_user;
            $other_title = isset($all_users[$other_user]) ? ($all_users[$other_user]['NAME'] != '' ? $all_users[$other_user]['NAME'] : $all_users[$other_user]['USERNAME']) : $row[$side . '_NAME'];
            $personal = 'user:' . $other_user;
        } elseif ($other_id) {
            $other = isset($id_names[$other_id]) ? $id_names[$other_id] : $row[$side . '_NAME'];
            $other_title = isset($all_terminals[$other]) ? $all_terminals[$other]['TITLE'] : $other;
            $personal = 'term:' . $other;
        } else {
            $other = '';
            $other_title = ($row[$side . '_NAME'] != '') ? $row[$side . '_NAME'] : $computer_name;
            $personal = 'system';
        }
        $chat_key = (isset($row['CHAT']) && $row['CHAT'] != '') ? $row['CHAT'] : $personal;
        $send_id = (isset($row['SEND_ID']) && $row['SEND_ID'] != '') ? $row['SEND_ID'] : 'id' . $row['ID'];
        $uniq = $chat_key . '|' . $send_id . '|' . ($incoming ? 'in' : 'out');
        // состояние отправленного: 1 — отправлено, 2 — доставлено, 3 — прочитано;
        // в группе — лучшее по всем получателям (как в мессенджерах: прочитал хотя бы один)
        $state = !empty($row['READ_AT']) ? 3 : (!empty($row['DELIVERED']) ? 2 : 1);
        if (isset($seen[$uniq])) {
            $k = $seen[$uniq];
            if (!$incoming && $state > $conversation[$k]['status']) {
                $conversation[$k]['status'] = $state;
            }
            $conversation[$k]['min_id'] = min($conversation[$k]['min_id'], (int)$row['ID']);
            continue;
        }
        if (count($conversation) >= $limit) {
            break;
        }
        $seen[$uniq] = count($conversation);
        $text = json_decode($row['MESSAGE'], true);
        $conversation[] = array(
            'id' => (int)$row['ID'],
            'min_id' => (int)$row['ID'],
            'chat' => $chat_key,
            'incoming' => $incoming,
            'other' => $other,
            'other_title' => $other_title,
            'text' => is_string($text) ? $text : '',
            'image' => ($row['IMAGE'] != '' && !preg_match('/^https?:/i', $row['IMAGE'])) ? ROOTHTML . ltrim($row['IMAGE'], '/') : $row['IMAGE'],
            'image_size' => $image_size($row['IMAGE']),
            'sound' => ($row['SOUND'] != '' && !preg_match('/^https?:/i', $row['SOUND'])) ? ROOTHTML . ltrim($row['SOUND'], '/') : $row['SOUND'],
            'video' => (!empty($row['VIDEO']) && !preg_match('/^https?:/i', $row['VIDEO'])) ? ROOTHTML . ltrim($row['VIDEO'], '/') : (isset($row['VIDEO']) ? $row['VIDEO'] : ''),
            // превью видео (кадр от браузера отправителя), если есть
            'video_poster' => (!empty($row['VIDEO']) && !preg_match('/^https?:/i', $row['VIDEO']) && is_file(ROOT . ltrim($row['VIDEO'], '/') . '.jpg'))
                ? ROOTHTML . ltrim($row['VIDEO'], '/') . '.jpg' : '',
            'file' => (!empty($row['FILE']) && !preg_match('/^https?:/i', $row['FILE'])) ? ROOTHTML . ltrim($row['FILE'], '/') : (isset($row['FILE']) ? $row['FILE'] : ''),
            'file_name' => isset($row['FILE_NAME']) ? $row['FILE_NAME'] : '',
            'time' => date('d.m H:i', (int)$row['ADDED']),
            'ts' => (int)$row['ADDED'],
            'status' => $incoming ? 0 : $state,
            'forwarded' => isset($row['FORWARDED']) ? (string)$row['FORWARDED'] : '',
            // камера go2rtc, показанная этим сообщением (снимок — в image)
            'stream' => isset($row['STREAM']) ? (string)$row['STREAM'] : '',
            'kind' => 'msg',
            'read' => ($incoming && !empty($row['READ_AT'])) ? 1 : 0,
        );
    }
    return $conversation;
};
// общий чат MajorDoMo и публичные комнаты (shouts).
// Свои — записанные с этого терминала (source terminalID) или его копии «всем» (source «terminal: Название»);
// без терминала — записанные этим пользователем
$build_shouts = function ($rows, $chat_key) use ($me, $user_id, $all_terminals, $computer_name, $image_size) {
    $out_rows = array();
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
        $mine = !empty($me['ID']) ? ($source == 'terminal' . $me['ID'] || $source == 'terminal: ' . $me['TITLE'])
            : ($user_id && (int)$row['MEMBER_ID'] == $user_id && strpos($source, 'terminal: ') !== 0);
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
        $out_rows[] = array(
            'id' => (int)$row['ID'],
            'min_id' => (int)$row['ID'],
            'chat' => $chat_key,
            'incoming' => !$mine,
            'other' => '',
            'other_title' => $author,
            'color' => $color,
            'text' => $text,
            'image' => $image,
            'image_size' => ($image != '') ? $image_size($row['IMAGE']) : array(),
            'sound' => '', 'video' => '', 'file' => '', 'file_name' => '', 'forwarded' => '', 'kind' => 'shout',
            'time' => date('d.m H:i', $ts),
            'ts' => $ts,
        );
    }
    return $out_rows;
};
$shout_chats = array(0 => 'house');
foreach ($shout_rooms as $room) {
    $shout_chats[(int)$room['ID']] = 'shout:' . $room['ID'];
}
$shout_select = function ($room_id, $before, $limit) {
    return SQLSelect("SELECT shouts.*, users.NAME AS USER_NAME, users.COLOR AS USER_COLOR FROM shouts"
        . " LEFT JOIN users ON shouts.MEMBER_ID=users.ID WHERE shouts.ROOM_ID=" . (int)$room_id
        . ($before > 0 ? " AND shouts.ID<" . (int)$before : '') . " ORDER BY shouts.ID DESC LIMIT " . (int)$limit);
};

// строки переписки чата, видимые этому терминалу (личный: между ним и собеседником; групповой: CHAT)
$chat_sql = function ($chat) use ($mine_sql, $to_me_sql, $from_me_sql, $party_sql) {
    if ($chat['type'] == 'term' || $chat['type'] == 'user' || $chat['type'] == 'system') {
        return "CHAT='' AND (($to_me_sql AND " . $party_sql($chat['key'], 'FROM') . " AND HIDE_TO=0)"
            . ($chat['type'] == 'system' ? '' : " OR ($from_me_sql AND " . $party_sql($chat['key'], 'TO') . " AND HIDE_FROM=0)") . ")";
    }
    return "CHAT='" . DBSafe($chat['key']) . "' AND $mine_sql";
};

// «Очистить историю» чата (меню ⋮ в заголовке): у себя — сообщения скрываются для этого терминала
// (у собеседников остаются); all=1 — из панели управления: удалить переписку чата у всех участников.
// Общий чат MajorDoMo и его комнаты — не очищаются (их ведёт ядро)
if (gr('ajax') && gr('mode') == 'clear_history' && $can_write) {
    $chat = $find_chat((string)gr('chat'));
    if (empty($chat['key']) || $chat['type'] == 'house' || $chat['type'] == 'shoutroom') {
        $json_out(array('ok' => 0, 'error' => 'not_found'));
    }
    $members = array_values(array_diff($chat['members'], array($me_name, $me_user_key)));
    $personal = ($chat['type'] == 'term' || $chat['type'] == 'user' || $chat['type'] == 'system');
    $to_cond = $personal ? "CHAT='' AND $to_me_sql AND " . $party_sql($chat['key'], 'FROM') : "CHAT='" . DBSafe($chat['key']) . "' AND $to_me_sql";
    $from_cond = $personal ? "CHAT='' AND $from_me_sql AND " . $party_sql($chat['key'], 'TO') : "CHAT='" . DBSafe($chat['key']) . "' AND $from_me_sql";
    if ($chat['type'] == 'system') {
        // «Система»: отправитель не терминал и не пользователь — сообщения удаляются сразу
        SQLExec("DELETE FROM terminals_messages WHERE $to_cond");
    } elseif (gr('all') && $as_admin) {
        SQLExec("DELETE FROM terminals_messages WHERE " . ($personal ? "($to_cond) OR ($from_cond)" : "CHAT='" . DBSafe($chat['key']) . "'"));
    } else {
        SQLExec("UPDATE terminals_messages SET HIDE_TO=1 WHERE $to_cond");
        SQLExec("UPDATE terminals_messages SET HIDE_FROM=1 WHERE $from_cond");
        // скрытые обеими сторонами больше никому не видны
        SQLExec("DELETE FROM terminals_messages WHERE HIDE_TO=1 AND (HIDE_FROM=1 OR (FROM_ID=0 AND FROM_USER=0))");
    }
    terminalsMessagesNotify($user_id ? $me_user_key : $me_name, $members);
    $json_out(array('ok' => 1));
}

// «Показать раньше»: ещё сообщения одного чата до номера before
if (gr('ajax') && gr('older') && $can_write) {
    $key = (string)gr('chat');
    $before = (int)gr('before');
    $chat = $find_chat($key);
    $messages = array();
    if (!empty($chat['key']) && $before > 0) {
        if ($chat['type'] == 'house' || $chat['type'] == 'shoutroom') {
            $room_id = array_search($chat['key'], $shout_chats, true);
            $messages = ($room_id !== false) ? $build_shouts($shout_select($room_id, $before, $page_older), $chat['key']) : array();
        } else {
            $cond = $chat_sql($chat);
            $rows = SQLSelect("SELECT * FROM terminals_messages WHERE $cond AND ID<$before ORDER BY ID DESC LIMIT " . ($page_older * 20));
            $messages = $build_messages($rows, $page_older);
        }
    }
    $json_out(array('messages' => $messages, 'more' => count($messages) >= $page_older ? 1 : 0));
}

// последние сообщения всех чатов; more — у каких чатов могут быть более ранние ('*' — у всех чатов терминалов)
$conversation = array();
$more = array();
if ($can_write) {
    $row_limit = $page_first * 5;
    $rows = SQLSelect("SELECT * FROM terminals_messages WHERE $mine_sql ORDER BY ID DESC LIMIT $row_limit");
    $conversation = $build_messages($rows, $page_first);
    if (count($conversation) >= $page_first || count($rows) >= $row_limit) {
        $more['*'] = 1;
    }
}
foreach ($shout_chats as $room_id => $chat_key) {
    $rows = $shout_select($room_id, 0, 50);
    if (count($rows) >= 50) {
        $more[$chat_key] = 1;
    }
    $conversation = array_merge($conversation, $build_shouts($rows, $chat_key));
}

if (gr('ajax') && gr('list')) {
    $json_out(array('messages' => $conversation, 'more' => (object)$more, 'chats' => $chats, 'status' => $statuses, 'colors' => $colors, 'emojis' => $emojis));
}

$out['ME_NAME'] = $me_name;
$out['ME_TITLE'] = !empty($me['ID']) ? $me['TITLE'] : '';
$out['AS_ADMIN'] = $as_admin ? 1 : 0;
$out['BY_KEY'] = $by_key ? 1 : 0;
$out['ALL_TERMINALS'] = array_values($all_terminals);
if (!$as_admin && $me_name == '') {
    // выбор терминала на неузнанном устройстве: терминалов «Только по ключу» (и новых, без ключа) в нём нет
    $open = array();
    foreach (SQLSelect("SELECT TERMINAL_ID FROM terminals_tokens WHERE REQUIRED=0") as $row) {
        $open[(int)$row['TERMINAL_ID']] = true;
    }
    $out['ALL_TERMINALS'] = array_values(array_filter($out['ALL_TERMINALS'], function ($t) use ($open) {
        return !empty($open[(int)$t['ID']]);
    }));
}
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$out['CONVERSATION_JSON'] = json_encode($conversation, $flags);
$out['MORE_JSON'] = json_encode((object)$more, $flags);
$out['CHATS_JSON'] = json_encode($chats, $flags);
$titles = array();
foreach ($all_terminals as $name => $t) {
    $titles[$name] = $t['TITLE'];
}
foreach ($all_users as $uid => $u) {
    $titles['user:' . $uid] = ($u['NAME'] != '') ? $u['NAME'] : $u['USERNAME'];
}
$out['ME_USER_KEY'] = $me_user_key;
$out['USER_BY_KEY'] = $user_by_key ? 1 : 0;
// без терминала, от пользователя: в заголовке — он
$out['USER_ONLY'] = ($me_name == '' && $user_id) ? 1 : 0;
$out['USER_TITLE'] = $user_id ? htmlspecialchars($titles['user:' . $user_id], ENT_QUOTES, 'UTF-8') : '';
$out['AVATARS_JSON'] = json_encode((object)$user_avatars, $flags);
$out['TITLES_JSON'] = json_encode((object)$titles, $flags);
$out['STATUS_JSON'] = json_encode((object)$statuses, $flags);
$out['COLORS_JSON'] = json_encode((object)$colors, $flags);
$out['EMOJIS_JSON'] = json_encode((object)$emojis, $flags);
// пользователь этой страницы — для карточки общего чата и заголовка без терминала
$user_name = $user_id ? $titles['user:' . $user_id] : '';
$out['USER_NAME_JSON'] = json_encode($user_name, $flags);
// пределы размера для проверки в браузере до отправки (МБ)
$out['MAX_MEDIA_MB'] = round($max_media / 1048576, 1);
$out['MAX_FILE_MB'] = round($max_file / 1048576, 1);
// вебсокет MajorDoMo: мгновенное обновление переписки; без него — опрос
$out['WS_PORT'] = (defined('WEBSOCKETS_PORT') && !(defined('DISABLE_WEBSOCKETS') && DISABLE_WEBSOCKETS == 1)) ? (int)WEBSOCKETS_PORT : '';
// внешний адрес вебсокета (настройка приложения «Сообщения»): используется, когда страница открыта
// не по локальному адресу (интернет, обратный прокси); локально — порт WEBSOCKETS_PORT на том же хосте
$this->getConfig();
$out['WS_EXTERNAL_JSON'] = json_encode(isset($this->config['MESSAGES_WS_EXTERNAL']) ? terminalsWsUrl($this->config['MESSAGES_WS_EXTERNAL']) : '', $flags);
$finish_session();
