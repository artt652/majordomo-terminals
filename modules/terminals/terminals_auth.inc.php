<?php
/*
 * Ключ доступа терминала к странице «Сообщения» и к её API.
 *
 * У каждого терминала свой ключ (таблица terminals_tokens). Он создаётся сам — при открытии карточки
 * терминала и при установке/обновлении модуля — и всегда виден в карточке (ссылка и QR-код): хранится
 * как есть, карточку видит только тот, кто вошёл в панель управления. На устройстве ключ открывают
 * ссылкой …/popup/app_messages.html?token=КЛЮЧ: страница запоминает его в cookie и дальше узнаёт
 * терминал по нему. Без ключа страница пускает только того, кто вошёл в панель управления в этом браузере.
 */

// Запись ключа терминала (или пустой массив)
function terminalsTokenRow($terminal_id)
{
    $row = SQLSelectOne("SELECT * FROM terminals_tokens WHERE TERMINAL_ID=" . (int)$terminal_id);
    return !empty($row['ID']) ? $row : array();
}

// Новый случайный ключ: 40 шестнадцатеричных знаков
function terminalsTokenNew()
{
    return function_exists('random_bytes') ? bin2hex(random_bytes(20)) : sha1(uniqid('', true) . mt_rand() . microtime());
}

// Создать ключ заново (прежний сразу перестаёт работать). Возвращает новый ключ
function terminalsTokenCreate($terminal_id)
{
    $row = terminalsTokenRow($terminal_id);
    if (empty($row['ID'])) {
        $row = array('TERMINAL_ID' => (int)$terminal_id);
    }
    $row['TOKEN'] = terminalsTokenNew();
    $row['CREATED'] = time();
    $row['LAST_USED'] = 0;
    if (!empty($row['ID'])) {
        SQLUpdate('terminals_tokens', $row);
    } else {
        SQLInsert('terminals_tokens', $row);
    }
    return $row['TOKEN'];
}

// Ключ терминала; нет — создать
function terminalsTokenGet($terminal_id)
{
    $row = terminalsTokenRow($terminal_id);
    if (!empty($row['TOKEN'])) {
        return $row['TOKEN'];
    }
    return terminalsTokenCreate($terminal_id);
}

// Ключи всем терминалам, у которых их нет (установка и обновление модуля)
function terminalsTokenEnsureAll()
{
    $list = SQLSelect("SELECT t.ID FROM terminals t LEFT JOIN terminals_tokens k ON k.TERMINAL_ID=t.ID WHERE k.TOKEN IS NULL OR k.TOKEN=''");
    foreach ((array)$list as $rec) {
        terminalsTokenCreate($rec['ID']);
    }
}

// Терминал по ключу (или пустой массив); отмечает, когда ключ использовался (не чаще раза в минуту)
function terminalsTokenFind($token)
{
    $token = strtolower(trim((string)$token));
    if (!preg_match('/^[0-9a-f]{32,64}$/', $token)) {
        return array();
    }
    $row = SQLSelectOne("SELECT * FROM terminals_tokens WHERE TOKEN='" . DBSafe($token) . "'");
    if (empty($row['ID'])) {
        return array();
    }
    $terminal_rec = getTerminalByID((int)$row['TERMINAL_ID']);
    if (empty($terminal_rec['ID'])) {
        return array();
    }
    if ((int)$row['LAST_USED'] < time() - 60) {
        SQLExec("UPDATE terminals_tokens SET LAST_USED=" . time() . " WHERE ID=" . (int)$row['ID']);
    }
    return $terminal_rec;
}

// Ключ в cookie устройства: HttpOnly (скрипты страницы его не видят), надолго, на весь сайт
function terminalsTokenCookie($token)
{
    $expire = ($token != '') ? time() + 10 * 365 * 24 * 3600 : time() - 3600;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off';
    if (PHP_VERSION_ID >= 70300) {
        setcookie('tmmsg_key', $token, array('expires' => $expire, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax'));
    } else {
        setcookie('tmmsg_key', $token, $expire, '/; samesite=Lax', '', $secure, true);
    }
}
