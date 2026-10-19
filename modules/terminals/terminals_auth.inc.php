<?php
/*
 * Ключ доступа терминала к странице «Сообщения» и к её API.
 *
 * Ключ создаётся в карточке терминала и показывается один раз (ссылка и QR-код); в базе хранится
 * только его хэш (sha256) — таблица terminals_tokens. На устройстве ключ открывают ссылкой
 * …/popup/app_messages.html?token=КЛЮЧ: страница запоминает его в cookie и дальше узнаёт терминал по нему.
 * «Требовать ключ» у терминала: без ключа (по адресу устройства, сессии или from=) страница его не откроет.
 * Общая настройка модуля MESSAGES_TOKEN_ONLY — страница только по ключу для всех терминалов.
 */

// Запись ключа терминала (или пустой массив)
function terminalsTokenRow($terminal_id)
{
    $row = SQLSelectOne("SELECT * FROM terminals_tokens WHERE TERMINAL_ID=" . (int)$terminal_id);
    return !empty($row['ID']) ? $row : array();
}

// Создать ключ (прежний перестаёт работать). Возвращает ключ — его показывают один раз
function terminalsTokenCreate($terminal_id)
{
    $token = function_exists('random_bytes') ? bin2hex(random_bytes(20)) : sha1(uniqid('', true) . mt_rand() . microtime());
    $row = terminalsTokenRow($terminal_id);
    if (empty($row['ID'])) {
        $row = array('TERMINAL_ID' => (int)$terminal_id, 'REQUIRED' => 0);
    }
    $row['TOKEN_HASH'] = hash('sha256', $token);
    $row['CREATED'] = time();
    $row['LAST_USED'] = 0;
    if (!empty($row['ID'])) {
        SQLUpdate('terminals_tokens', $row);
    } else {
        SQLInsert('terminals_tokens', $row);
    }
    return $token;
}

// Отозвать ключ (настройка «Требовать ключ» остаётся)
function terminalsTokenRevoke($terminal_id)
{
    SQLExec("UPDATE terminals_tokens SET TOKEN_HASH='', CREATED=0, LAST_USED=0 WHERE TERMINAL_ID=" . (int)$terminal_id);
}

// «Требовать ключ»
function terminalsTokenSetRequired($terminal_id, $required)
{
    $row = terminalsTokenRow($terminal_id);
    if (empty($row['ID'])) {
        $row = array('TERMINAL_ID' => (int)$terminal_id, 'TOKEN_HASH' => '', 'CREATED' => 0, 'LAST_USED' => 0);
    }
    $row['REQUIRED'] = $required ? 1 : 0;
    if (!empty($row['ID'])) {
        SQLUpdate('terminals_tokens', $row);
    } else {
        SQLInsert('terminals_tokens', $row);
    }
}

function terminalsTokenRequired($terminal_id)
{
    $row = terminalsTokenRow($terminal_id);
    return !empty($row['REQUIRED']);
}

// Терминал по ключу (или пустой массив); отмечает, когда ключ использовался (не чаще раза в минуту)
function terminalsTokenFind($token)
{
    $token = trim((string)$token);
    if (!preg_match('/^[0-9a-f]{32,64}$/i', $token)) {
        return array();
    }
    $row = SQLSelectOne("SELECT * FROM terminals_tokens WHERE TOKEN_HASH='" . DBSafe(hash('sha256', strtolower($token))) . "'");
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
