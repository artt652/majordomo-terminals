<?php
/*
 * Камеры через go2rtc (https://github.com/AlexxIT/go2rtc): потоки, снимки, показ на терминалах, внешний просмотр.
 *
 * Своих камер у MajorDoMo нет — потоки заводятся в go2rtc, модуль берёт из него только имена (GET /api/streams).
 * Адреса камер (в go2rtc — с паролями) дальше сервера не уходят. В go2rtc всегда передаётся только имя
 * потока из этого списка: параметр src в go2rtc принимает и ссылку (создаёт поток на лету), поэтому текст
 * от пользователя туда не попадает.
 *
 * Настройки — в конфигурации модуля terminals (настройки «Сообщений» в панели управления):
 *   GO2RTC_URL, GO2RTC_USER, GO2RTC_PASSWORD — API для сервера (пусто — камер нет). С той же машины
 *     go2rtc пароль не спрашивает (кроме local_auth: true);
 *   GO2RTC_DEVICE_URL — адрес go2rtc для устройств (пусто — адрес сервера в сети и порт из GO2RTC_URL);
 *   GO2RTC_RTSP_PORT, GO2RTC_RTSP_USER, GO2RTC_RTSP_PASSWORD — RTSP-сервер go2rtc (свой пароль, только просмотр);
 *   GO2RTC_WEBTORRENT, GO2RTC_WT_MINUTES — внешний просмотр через WebTorrent и срок ссылки;
 *   GO2RTC_WAIT — сколько ждать первый кадр камеры, с (снимок, начало просмотра; пусто — 6);
 *   GO2RTC_SHARES — действующие ссылки WebTorrent: имя потока => до какого времени.
 * Пароль API никуда не уходит: устройства смотрят по RTSP (пароль RTSP) или WebRTC через сервер
 * (страница «Сообщения», mode=whep — согласование по ключу терминала, видео идёт напрямую с go2rtc).
 */

// Конфигурация модуля terminals (как module::getConfig(), без объекта модуля)
function terminalsModuleConfig()
{
    $rec = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='terminals'");
    $config = !empty($rec['DATA']) ? @unserialize($rec['DATA']) : array();
    return is_array($config) ? $config : array();
}

function terminalsModuleConfigSave($config)
{
    $rec = SQLSelectOne("SELECT * FROM project_modules WHERE NAME='terminals'");
    if (!empty($rec['ID'])) {
        $rec['DATA'] = serialize($config);
        SQLUpdate('project_modules', $rec);
    }
}

// Настройки go2rtc (с умолчаниями)
function terminalsGo2rtcConfig()
{
    $config = terminalsModuleConfig();
    $get = function ($key, $default = '') use ($config) {
        return (isset($config[$key]) && $config[$key] !== '') ? (string)$config[$key] : $default;
    };
    return array(
        'url' => rtrim($get('GO2RTC_URL'), '/'),
        'user' => $get('GO2RTC_USER'),
        'password' => $get('GO2RTC_PASSWORD'),
        'device_url' => rtrim($get('GO2RTC_DEVICE_URL'), '/'),
        'rtsp_port' => (int)$get('GO2RTC_RTSP_PORT', '8554'),
        'rtsp_user' => $get('GO2RTC_RTSP_USER'),
        'rtsp_password' => $get('GO2RTC_RTSP_PASSWORD'),
        'webtorrent' => $get('GO2RTC_WEBTORRENT') == '1',
        'wt_minutes' => max(1, min(1440, (int)$get('GO2RTC_WT_MINUTES', '10'))),
        // удалённые и облачные камеры просыпаются дольше; не больше 25 с — запрос страницы укладывается в 30 с PHP
        'wait' => max(2, min(25, (int)$get('GO2RTC_WAIT', '6'))),
    );
}

// Камеры настроены (задан адрес go2rtc)
function terminalsGo2rtcEnabled()
{
    $cfg = terminalsGo2rtcConfig();
    return $cfg['url'] != '';
}

// Запрос к API go2rtc с сервера. Возвращает тело ответа; $code — HTTP-код (0 — нет связи), $type — Content-Type
function terminalsGo2rtcRequest($method, $path, $query = array(), $body = null, $content_type = '', &$code = 0, $timeout = 5, &$type = '')
{
    $cfg = terminalsGo2rtcConfig();
    $code = 0;
    $type = '';
    if ($cfg['url'] == '' || !function_exists('curl_init')) {
        return '';
    }
    $url = $cfg['url'] . '/' . ltrim($path, '/') . (count($query) ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($cfg['user'] != '') {
        curl_setopt($ch, CURLOPT_USERPWD, $cfg['user'] . ':' . $cfg['password']);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($content_type != '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $content_type));
    }
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return is_string($result) ? $result : '';
}

// Имена потоков go2rtc (по алфавиту); go2rtc не ответил — пустой список. Список запоминается на минуту
// (cms/cached/terminals_go2rtc.json): страница «Сообщения» обновляется часто, а выключенный go2rtc
// иначе задерживал бы каждое обновление
function terminalsGo2rtcStreams()
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $cache_file = ROOT . 'cms/cached/terminals_go2rtc.json';
    $cfg = terminalsGo2rtcConfig();
    $cache = is_file($cache_file) ? json_decode((string)@file_get_contents($cache_file), true) : null;
    if (is_array($cache) && isset($cache['time'], $cache['url'], $cache['names']) && $cache['url'] === $cfg['url']
        && $cache['time'] > time() - 60 && is_array($cache['names'])) {
        $names = $cache['names'];
        return $names;
    }
    $names = array();
    $data = json_decode(terminalsGo2rtcRequest('GET', 'api/streams', array(), null, '', $code), true);
    if ($code == 200 && is_array($data)) {
        foreach (array_keys($data) as $name) {
            // любое имя go2rtc (пробелы, скобки, кириллица — в ссылках оно кодируется), кроме управляющих
            // символов; до 100 символов — столько хранит переписка (terminals_messages.STREAM)
            if (is_string($name) && preg_match('/^[^\x00-\x1f\x7f]{1,100}$/u', $name)) {
                $names[] = $name;
            }
        }
        natcasesort($names);
        $names = array_values($names);
    } elseif ($code != 200) {
        DebMes('go2rtc: streams list failed (HTTP ' . $code . ')', 'terminals');
    }
    @file_put_contents($cache_file, json_encode(array('time' => time(), 'url' => $cfg['url'], 'names' => $names)));
    return $names;
}

function terminalsGo2rtcHas($name)
{
    return is_string($name) && $name != '' && in_array($name, terminalsGo2rtcStreams(), true);
}

// Адрес go2rtc для устройств: настройка или адрес сервера в сети с портом API
function terminalsGo2rtcDeviceBase()
{
    $cfg = terminalsGo2rtcConfig();
    if ($cfg['device_url'] != '') {
        return $cfg['device_url'];
    }
    $parts = parse_url($cfg['url']);
    $host = isset($parts['host']) ? $parts['host'] : '';
    if ($host == '' || $host == '127.0.0.1' || $host == 'localhost' || $host == '::1') {
        $ip = function_exists('getLocalIp') ? getLocalIp() : '';
        $host = $ip ? $ip : $host;
    }
    return (isset($parts['scheme']) ? $parts['scheme'] : 'http') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ':1984');
}

// Ссылка на поток для устройства: rtsp, hls, mjpeg, frame (снимок), webrtc (через сервер, по ключу терминала)
function terminalsGo2rtcUrl($name, $format = 'rtsp', $terminal_rec = array())
{
    if (!terminalsGo2rtcHas($name)) {
        return '';
    }
    $cfg = terminalsGo2rtcConfig();
    $base = terminalsGo2rtcDeviceBase();
    $src = rawurlencode($name);
    if ($format == 'rtsp') {
        $host = (string)parse_url($base, PHP_URL_HOST);
        $auth = ($cfg['rtsp_user'] != '') ? rawurlencode($cfg['rtsp_user']) . ':' . rawurlencode($cfg['rtsp_password']) . '@' : '';
        return 'rtsp://' . $auth . $host . ':' . $cfg['rtsp_port'] . '/' . $src;
    }
    if ($format == 'hls') {
        return $base . '/api/stream.m3u8?src=' . $src . '&mp4';
    }
    if ($format == 'mjpeg') {
        return $base . '/api/stream.mjpeg?src=' . $src;
    }
    if ($format == 'frame') {
        return $base . '/api/frame.jpeg?src=' . $src;
    }
    if ($format == 'webrtc') {
        // согласование WebRTC — через страницу «Сообщения» (пароль API устройству не нужен), видео — с go2rtc напрямую
        $server = (defined('BASE_URL') ? BASE_URL : '');
        $ip = function_exists('getLocalIp') ? getLocalIp() : '';
        if ($ip && preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?#i', $server, $m)) {
            $server = 'http://' . $ip . (isset($m[2]) ? $m[2] : '');
        }
        // ключ терминала — кто спрашивает; подпись (exp, sig) — что эту камеру ему показали (в том числе сценарием,
        // без прав на неё): страница пустит к этому потоку до exp
        $token = !empty($terminal_rec['ID']) ? terminalsTokenGet($terminal_rec['ID']) : '';
        $exp = time() + 3600;
        return rtrim($server, '/') . '/popup/app_messages.html?ajax=1&mode=whep&src=' . $src
            . ($token != '' ? '&token=' . $token . '&exp=' . $exp . '&sig=' . terminalsGo2rtcSign($name, $terminal_rec['ID'], $exp) : '');
    }
    return '';
}

// Подпись ссылки WebRTC для терминала: поток, терминал, срок; ключ — ключ терминала (terminals_auth.inc.php)
function terminalsGo2rtcSign($name, $terminal_id, $exp)
{
    return hash_hmac('sha256', $name . '|' . (int)$terminal_id . '|' . (int)$exp, terminalsTokenGet((int)$terminal_id));
}

// Снимок потока (JPEG) или пустая строка; $width — ширина (0 — как у камеры). cache — не дёргать камеру чаще раза в 5 с
function terminalsGo2rtcFrame($name, $width = 0)
{
    if (!terminalsGo2rtcHas($name) || terminalsGo2rtcDown($name)) {
        return '';
    }
    $query = array('src' => $name, 'cache' => '5s');
    if ($width > 0) {
        $query['width'] = (int)$width;
    }
    // камера просыпается (go2rtc подключается к ней) обычно за 1–3 с, удалённая — дольше; не ответила
    // за GO2RTC_WAIT (по умолчанию 6 с) — считаем недоступной
    $cfg = terminalsGo2rtcConfig();
    $jpeg = terminalsGo2rtcRequest('GET', 'api/frame.jpeg', $query, null, '', $code, $cfg['wait'], $type);
    $ok = ($code == 200 && strpos($type, 'image/jpeg') === 0 && $jpeg != '');
    if (!$ok) {
        terminalsGo2rtcDown($name, true);
    }
    return $ok ? $jpeg : '';
}

// Недоступная камера: снимок не получился — следующие 30 с к ней не обращаемся (ответ сразу), чтобы
// страница и показ не ждали её каждый раз. $set === true — отметить недоступной. cms/cached/terminals_go2rtc_down.json
function terminalsGo2rtcDown($name, $set = null)
{
    $file = ROOT . 'cms/cached/terminals_go2rtc_down.json';
    $down = is_file($file) ? json_decode((string)@file_get_contents($file), true) : array();
    $down = is_array($down) ? $down : array();
    if ($set === true) {
        $down[$name] = time();
        foreach ($down as $key => $time) {
            if ($time < time() - 30) {
                unset($down[$key]);
            }
        }
        @file_put_contents($file, json_encode($down));
        DebMes('go2rtc: camera not responding: ' . $name, 'terminals');
        return true;
    }
    return isset($down[$name]) && $down[$name] > time() - 30;
}

// Снимок потока — файлом в cms/cached/terminals (для сообщения); путь от веб-корня или ''
function terminalsGo2rtcSnapshot($name)
{
    $jpeg = terminalsGo2rtcFrame($name);
    if ($jpeg == '') {
        return '';
    }
    $dir = ROOT . 'cms/cached/terminals';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = 'cms/cached/terminals/' . md5(uniqid('', true) . mt_rand()) . '.jpg';
    return @file_put_contents(ROOT . $file, $jpeg) ? $file : '';
}

// Показать поток на терминале на $duration секунд. PiPup — окно видео (RTSP или WebRTC — настройка «Камеры»
// в озвучке pipup), повторный вызов продлевает то же окно; mediaplayer — плеером через очередь (RTSP),
// после показа — остановка или возврат музыки; остальные — снимок и подпись. Возвращает, показано ли
function terminalsGo2rtcShow($terminal_rec, $name, $duration = 60, $title = '', $level = 0)
{
    if (!terminalsGo2rtcHas($name) || empty($terminal_rec['ID'])) {
        return false;
    }
    $duration = max(5, min(3600, (int)$duration));
    $title = ($title != '') ? $title : terminalsLang('TERMINALS_CAMERA_TITLE') . ': ' . $name;
    $tts = ($terminal_rec['CANTTS'] && $terminal_rec['TTS_TYPE'] != '') ? terminalsQueueAddon($terminal_rec) : null;
    if (is_object($tts) && !terminalsReachable($terminal_rec, $tts)) {
        return false;
    }
    if (is_object($tts) && method_exists($tts, 'cameraFormat')) {
        // pipup: окно потока сразу, поверх (потоки в очередь не ставятся — окно продлевается по тому же id)
        $format = $tts->cameraFormat();
        $url = terminalsGo2rtcUrl($name, $format, $terminal_rec);
        $poster = terminalsGo2rtcUrl($name, 'frame');
        // в журнал — ссылки, которые получает устройство (пароль, ключ и подпись — ***)
        DebMes('go2rtc: show ' . $name . ' (' . $format . ') on ' . $terminal_rec['TITLE'] . ' (' . terminalsDeviceLabel($terminal_rec, $tts) . '): '
            . terminalsLogSafe($url) . ', poster ' . terminalsLogSafe($poster), 'terminals');
        return (bool)$tts->sendVideo($url, '', $title, ($format == 'webrtc') ? 'whep' : 'video', array(
            'duration' => $duration, 'id' => 'cam_' . $name, 'muted' => false, 'liveBadge' => true,
            'poster' => $poster));
    }
    if (is_object($tts) && method_exists($tts, 'sendVideo') && method_exists($tts, 'canShowVideo') && $tts->canShowVideo()) {
        // плеер с экраном: через очередь, держит терминал на время показа
        $url = terminalsGo2rtcUrl($name, 'rtsp');
        DebMes('go2rtc: show ' . $name . ' (rtsp) on ' . $terminal_rec['TITLE'] . ' (' . terminalsDeviceLabel($terminal_rec, $tts) . '): '
            . terminalsLogSafe($url), 'terminals');
        return (bool)terminalsQueueCall($terminal_rec, 'sendVideo', array($url, '', $title, 'stream', array('duration' => $duration)),
            array('level' => $level, 'busy' => $duration));
    }
    return false;
}

// Внешний просмотр через WebTorrent: ссылка на поток (создаётся или продлевается). Пустой массив — выключено
// или go2rtc не ответил. Ссылка живёт GO2RTC_WT_MINUTES после последнего запроса (чистка — MINUTELY)
function terminalsGo2rtcShare($name)
{
    $cfg = terminalsGo2rtcConfig();
    if (!$cfg['webtorrent'] || !terminalsGo2rtcHas($name)) {
        return array();
    }
    $data = json_decode(terminalsGo2rtcRequest('GET', 'api/webtorrent', array('src' => $name), null, '', $code), true);
    if ($code != 200 || empty($data['share'])) {
        $data = json_decode(terminalsGo2rtcRequest('POST', 'api/webtorrent', array('src' => $name), '', '', $code), true);
    }
    if (empty($data['share']) || empty($data['pwd'])) {
        DebMes('go2rtc: webtorrent share failed for ' . $name . ' (HTTP ' . $code . ')', 'terminals');
        return array();
    }
    $config = terminalsModuleConfig();
    $shares = (isset($config['GO2RTC_SHARES']) && is_array($config['GO2RTC_SHARES'])) ? $config['GO2RTC_SHARES'] : array();
    $shares[$name] = time() + $cfg['wt_minutes'] * 60;
    $config['GO2RTC_SHARES'] = $shares;
    terminalsModuleConfigSave($config);
    return array(
        'url' => 'https://go2rtc.org/webtorrent/#share=' . rawurlencode($data['share']) . '&pwd=' . rawurlencode($data['pwd']) . '&media=video+audio',
        'expires' => $shares[$name],
    );
}

// Закрыть ссылку WebTorrent на поток (ссылка перестаёт работать у всех)
function terminalsGo2rtcUnshare($name)
{
    terminalsGo2rtcRequest('DELETE', 'api/webtorrent', array('src' => $name), null, '', $code);
    $config = terminalsModuleConfig();
    if (isset($config['GO2RTC_SHARES'][$name])) {
        unset($config['GO2RTC_SHARES'][$name]);
        terminalsModuleConfigSave($config);
    }
    return $code == 200 || $code == 404;
}

// Действующие ссылки WebTorrent: имя потока => до какого времени
function terminalsGo2rtcShares()
{
    $config = terminalsModuleConfig();
    return (isset($config['GO2RTC_SHARES']) && is_array($config['GO2RTC_SHARES'])) ? $config['GO2RTC_SHARES'] : array();
}

// MINUTELY: закрыть ссылки, срок которых вышел
function terminalsGo2rtcSharesCleanup()
{
    foreach (terminalsGo2rtcShares() as $name => $expires) {
        if ((int)$expires < time()) {
            terminalsGo2rtcUnshare($name);
        }
    }
}

// --- Права на камеры ---
// Настройка CAMERA_ACCESS: имя потока => array('all' => 1|0, 'terminals' => array(имена), 'users' => array(ID)).
// Администратор (вход в панель управления или пользователь MajorDoMo с правами администратора) видит все камеры
// и задаёт права (настройки «Сообщений»). Камера без записи в правах — только администратору.
// Сценарии (terminalsSend с 'stream', terminalsGo2rtcShow) правами не ограничены.
function terminalsCameraAccess()
{
    $config = terminalsModuleConfig();
    return (isset($config['CAMERA_ACCESS']) && is_array($config['CAMERA_ACCESS'])) ? $config['CAMERA_ACCESS'] : array();
}

function terminalsCameraAccessSave($access)
{
    $config = terminalsModuleConfig();
    $config['CAMERA_ACCESS'] = $access;
    terminalsModuleConfigSave($config);
}

// Видна ли камера: терминалу (имя), пользователю (ID) или администратору
function terminalsCameraAllowed($name, $terminal_name, $user_id, $is_admin)
{
    if ($is_admin) {
        return true;
    }
    $access = terminalsCameraAccess();
    if (!isset($access[$name]) || !is_array($access[$name])) {
        return false;
    }
    $rule = $access[$name];
    if (!empty($rule['all'])) {
        return true;
    }
    if ($terminal_name != '' && !empty($rule['terminals']) && in_array($terminal_name, (array)$rule['terminals'], true)) {
        return true;
    }
    return $user_id > 0 && !empty($rule['users']) && in_array((int)$user_id, array_map('intval', (array)$rule['users']), true);
}

// Камеры, видимые терминалу, пользователю или администратору
function terminalsCamerasFor($terminal_name, $user_id, $is_admin)
{
    $result = array();
    foreach (terminalsGo2rtcStreams() as $name) {
        if (terminalsCameraAllowed($name, $terminal_name, $user_id, $is_admin)) {
            $result[] = $name;
        }
    }
    return $result;
}
