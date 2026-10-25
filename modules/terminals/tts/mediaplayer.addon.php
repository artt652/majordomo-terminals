<?php
/*
 * Сообщения на плеер терминала (тип плеера — в разделе «Может воспроизводить медиа»).
 *
 * Один тип TTS для всех плееров app_player, как в оригинале; особенности плееров — здесь же, по типу плеера:
 * - mpd, lms, kodi: play() их дополнений заменяет плейлист — сообщение добавляется в конец плейлиста (pl_add)
 *   и включается по номеру (pl_play), после сообщений возвращается прежний трек по номеру. Номер — только
 *   если однозначен в плейлисте, иначе — запуск файла. У mpd в статусе track_id — позиция, а не номер трека;
 * - chromecast: перемотки в app_player нет — прежний трек загружается сразу с позиции (GChromecast::load);
 * - dnla: многие устройства не играют wav — перед отправкой wav перекодируется в mp3 (как say_message() в dnla).
 * lms, kodi, chromecast, dnla написаны по коду их дополнений app_player, на устройствах не проверялись.
 */

include_once(DIR_MODULES . 'terminals/terminals_settings.inc.php');

class mediaplayer extends tts_addon
{
    // Свои свойства: в базовом tts_addon $title объявлен private, запись из наследника
    // создавала бы динамическое свойство (Deprecated в PHP 8.2+). Описание показывается в карточке терминала
    public $title = 'MediaPlayer';
    public $description = '';

    function __construct($terminal)
    {
        $this->title = "MediaPlayer";
        $this->description = terminalsLang('TERMINALS_DESC_MEDIAPLAYER') . ' ' . terminalsLang('TERMINALS_DESC_SIGNAL');
        parent::__construct($terminal);
    }

    // Тип плеера терминала (mpd, lms, kodi, chromecast, dnla, vlcweb, …)
    protected function playerType()
    {
        return strtolower(isset($this->terminal['PLAYER_TYPE']) ? (string)$this->terminal['PLAYER_TYPE'] : '');
    }

    // Хост и порт плеера — проверка, что устройство на связи (terminalsReachable). Порт — из настроек плеера,
    // иначе обычный для типа (vlcweb, kodi — 8080, mpd — 6600, chromecast — 8009); не знаем порт — 0 (без проверки)
    function deviceAddress()
    {
        $host = preg_replace('/:\d*$/', '', trim(isset($this->terminal['HOST']) ? (string)$this->terminal['HOST'] : ''));
        $port = !empty($this->terminal['PLAYER_PORT']) ? (int)$this->terminal['PLAYER_PORT'] : 0;
        if ($port <= 0) {
            $defaults = array('vlcweb' => 8080, 'kodi' => 8080, 'mpd' => 6600, 'chromecast' => 8009);
            $port = isset($defaults[$this->playerType()]) ? $defaults[$this->playerType()] : 0;
        }
        return array($host, $port);
    }

    // Плееры, у которых play() заменяет плейлист: сообщение — в конец плейлиста
    protected function messageInPlaylist()
    {
        return in_array($this->playerType(), array('mpd', 'lms', 'kodi'), true);
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        if ($this->playerType() == 'dnla') {
            $cached_file = $this->dlnaFile($cached_file);
        }
        $cached_file_url = $this->messageUrl($cached_file);
        if ($cached_file_url == '') {
            return false;
        }
        if ($this->messageInPlaylist() && $this->playInPlaylist($cached_file_url)) {
            return true;
        }
        $this->applyMessageVolume();
        // очередь, порядок и восстановление плеера ведёт модуль terminals (terminals_queue.inc.php)
        playMedia($cached_file_url, $this->terminal['TITLE']);
        return true;
    }

    // Звуковой файл (голосовое, музыка или звук перед сообщением из terminalsSend, сигнал очереди): путь в веб-корне MajorDoMo или http-ссылка.
    // Играет так же, как файл голоса
    public function playFile($file, $level = 0, $title = '')
    {
        return $this->sayCached('', $level, $file);
    }

    // Ссылка, по которой плеер заберёт файл сообщения: файл голоса из cms/cached, другой файл
    // в веб-корне MajorDoMo или http-ссылка (пусто, если файл вне веб-корня)
    protected function messageUrl($cached_file)
    {
        if (preg_match('/^https?:\/\//i', (string)$cached_file)) {
            return (string)$cached_file;
        }
        if (!preg_match('/\/cms\/cached.+/', $cached_file)) {
            // файл в веб-корне (например, cms/sounds/dingdong.mp3)
            $path = is_file($cached_file) ? $cached_file : ROOT . ltrim((string)$cached_file, '/');
            $real = realpath($path);
            $root = realpath(ROOT);
            if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
                return '';
            }
            $cached_file = '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
            if (preg_match('/\/cms\/cached.+/', $cached_file) == 0) {
                $server_ip = getLocalIp();
                $url = $server_ip ? 'http://' . $server_ip . $cached_file : '';
                if ($url == '' || !remote_file_exists($url)) {
                    $url = BASE_URL . $cached_file;
                }
                return $url;
            }
        }
        // poluchaem adress cashed files dlya zapuska ego na vosproizvedeniye
        if (preg_match('/\/cms\/cached.+/', $cached_file, $m)) {
            $server_ip = getLocalIp();
            if (!$server_ip) {
                //DebMes("Server IP not found", 'terminals');
                return '';
            } else {
                $cached_file_url = 'http://' . $server_ip . $m[0];
            }
            // как в dnla.addon.php: если по адресу без порта файл недоступен, берём адрес из BASE_URL
            if (!remote_file_exists($cached_file_url)) {
                $cached_file_url = BASE_URL . $m[0];
            }
            return $cached_file_url;
        }
        return '';
    }

    // Поля карточки терминала для этого типа TTS (modules/terminals/terminals_settings.inc.php)
    function settingsFields()
    {
        return array(
            array('NAME' => 'MESSAGE_VOLUME', 'TITLE' => terminalsLang('TERMINALS_MESSAGE_VOLUME'), 'TYPE' => 'number',
                'HINT' => terminalsLang('TERMINALS_MESSAGE_VOLUME_HINT')),
            terminalsSignalField(),
            array('NAME' => 'SHOW_VIDEO', 'TITLE' => terminalsLang('TERMINALS_SHOW_VIDEO'), 'TYPE' => 'checkbox',
                'DEFAULT' => $this->showVideoDefault() ? '1' : '0'),
            // камеры (модуль go2rtc): RTSP — VLC без модуля live555 (сборки Debian, Raspberry Pi OS)
            // и Chromecast его не играют; HTTP (MP4) — с API go2rtc, по TCP, нужен доступ к нему без пароля
            array('NAME' => 'CAMERA_FORMAT', 'TITLE' => terminalsLang('TERMINALS_PLAYER_CAMERA_FORMAT'), 'TYPE' => 'select',
                'OPTIONS' => array('rtsp' => 'RTSP', 'mp4' => 'HTTP (MP4)'), 'DEFAULT' => $this->streamFormatDefault(),
                'HINT' => terminalsLang('TERMINALS_PLAYER_CAMERA_FORMAT_HINT')),
        );
    }

    // Формат камер по умолчанию: Chromecast и DLNA RTSP не играют
    protected function streamFormatDefault()
    {
        return in_array($this->playerType(), array('chromecast', 'dnla'), true) ? 'mp4' : 'rtsp';
    }

    // Как показывать камеры go2rtc: rtsp или mp4 (поле «Камеры»)
    public function streamFormat()
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'mediaplayer') : array();
        $format = isset($settings['CAMERA_FORMAT']) ? $settings['CAMERA_FORMAT'] : '';
        return in_array($format, array('rtsp', 'mp4'), true) ? $format : $this->streamFormatDefault();
    }

    // Плееры с экраном (у остальных — только звук: mpd, lms, ghn, foobar)
    protected function videoPlayer()
    {
        return in_array($this->playerType(), array('vlcweb', 'vlc', 'kodi', 'chromecast', 'dnla'), true);
    }

    // «Показывать видео из сообщений» по умолчанию: у плееров с экраном, кроме MAIN (его плеер обычно
    // на самом сервере — окно ролика там вряд ли нужно)
    protected function showVideoDefault()
    {
        return $this->videoPlayer() && strcasecmp(isset($this->terminal['NAME']) ? (string)$this->terminal['NAME'] : '', 'MAIN') != 0;
    }

    // Показывает ли терминал видео (terminalsSend, карточка терминала): плеер с экраном и галочка в настройках
    public function canShowVideo()
    {
        if (!$this->videoPlayer()) {
            return false;
        }
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'mediaplayer') : array();
        return isset($settings['SHOW_VIDEO']) ? $settings['SHOW_VIDEO'] == '1' : $this->showVideoDefault();
    }

    // Видео из сообщений и сценариев — плеером терминала, через очередь модуля terminals (как файл голоса:
    // перед роликом сохраняется, что играло, после — возвращается). Аргументы — как у pipup::sendVideo():
    // файл в веб-корне, http(s)- или rtsp/rtmp-ссылка; $text, $title, $type, $options не нужны плееру.
    // DLNA — только mp4: webm многие телевизоры не играют
    public function sendVideo($uri, $text = '', $title = '', $type = 'video', $options = array())
    {
        if (!$this->canShowVideo()) {
            return false;
        }
        $uri = (string)$uri;
        if ($this->playerType() == 'dnla' && !in_array(strtolower(pathinfo((string)parse_url($uri, PHP_URL_PATH), PATHINFO_EXTENSION)), array('mp4', 'm4v'), true)) {
            return false;
        }
        // поток: rtsp/rtmp-ссылка или $type == 'stream' (камера go2rtc, в том числе http …/api/stream.mp4)
        $stream = (bool)preg_match('/^(rtsp|rtsps|rtmp|rtmps):\/\//i', $uri) || ($type == 'stream' && preg_match('#^https?://#i', $uri));
        $url = $stream ? $uri : $this->messageUrl($uri);
        if ($url == '') {
            return false;
        }
        $this->applyMessageVolume();
        playMedia($url, $this->terminal['TITLE']);
        // поток (камера) не кончается сам: через duration остановить, если плеер всё ещё играет его
        // (если очередь уже вернула музыку — не трогать)
        if ($stream && !empty($options['duration'])) {
            setTimeOut('terminals_stream_stop_' . (int)$this->terminal['ID'],
                'include_once(DIR_MODULES . "terminals/terminals.class.php"); include_once(DIR_MODULES . "terminals/tts_addon.class.php"); '
                . 'include_once(DIR_MODULES . "terminals/tts/mediaplayer.addon.php"); $rec = getTerminalByID(' . (int)$this->terminal['ID'] . '); '
                . 'if (!empty($rec["ID"])) { $mp = new mediaplayer($rec); $mp->stopStream(' . var_export($url, true) . '); }',
                (int)$options['duration'] + 2);
        }
        return true;
    }

    // Остановить плеер, если он играет этот поток
    public function stopStream($url)
    {
        $this->playerSession();
        $played = getPlayerStatus($this->terminal['NAME']);
        if (is_array($played) && isset($played['state']) && $played['state'] == 'playing' && $this->currentFile($played) == $url) {
            $this->playerCommand('stop');
            return true;
        }
        return false;
    }

    // Громкость сообщений из настроек (1–100) или 0, если не задана
    protected function messageVolume()
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', get_class($this)) : array();
        $volume = isset($settings['MESSAGE_VOLUME']) ? (int)$settings['MESSAGE_VOLUME'] : 0;
        return ($volume > 0 && $volume <= 100) ? $volume : 0;
    }

    // Перед сообщением выставить громкость сообщений. Только если плеер сообщает свою громкость:
    // иначе её нечем будет вернуть после сообщений
    protected function applyMessageVolume()
    {
        $volume = $this->messageVolume();
        if (!$volume) {
            return;
        }
        $this->playerSession();
        $played = getPlayerStatus($this->terminal['NAME']);
        if (is_array($played) && isset($played['volume']) && (int)$played['volume'] != $volume) {
            setPlayerVolume($this->terminal['TITLE'], $volume);
        }
    }

    // Сообщения этого терминала идут через очередь модуля terminals
    public function useQueue()
    {
        return true;
    }

    // Что играет плеер: файл, позиция, громкость. Пусто, если не играет или играет сообщение
    public function saveState()
    {
        $this->playerSession();
        $played = getPlayerStatus($this->terminal['NAME']);
        if (!is_array($played) || !isset($played['state'])) {
            return false;
        }
        $volume = (isset($played['volume']) && (int)$played['volume'] > 0) ? (int)$played['volume'] : 0;
        $file = ($played['state'] == 'playing') ? $this->currentFile($played) : '';
        if ($file != '' && !$this->isMessageFile($file)) {
            $state = array('file' => $file, 'time' => isset($played['time']) ? (int)$played['time'] : 0);
            $track_id = $this->currentTrackId($played);
            if ($track_id >= 0) {
                $state['track_id'] = $track_id;
            }
            if ($volume) {
                $state['volume'] = $volume;
            }
            return $state;
        }
        // музыка не играет, но для сообщений своя громкость — запомнить прежнюю, чтобы вернуть её
        if ($volume && $this->messageVolume()) {
            return array('volume' => $volume);
        }
        return false;
    }

    // Номер текущего трека в плейлисте (для возврата через pl_play) или -1.
    // У mpd track_id — позиция трека, номер — у элемента плейлиста в этой позиции
    protected function currentTrackId($played)
    {
        if ($this->playerType() == 'mpd') {
            $position = isset($played['track_id']) ? (int)$played['track_id'] : -1;
            return ($position >= 0 && isset($played[$position]['id'])) ? (int)$played[$position]['id'] : -1;
        }
        return (isset($played['track_id']) && (int)$played['track_id'] >= 0) ? (int)$played['track_id'] : -1;
    }

    // Играет ли плеер сообщение: true — да, false — нет, null — неизвестно (тогда очередь
    // ориентируется на расчётное время)
    public function isPlaying()
    {
        $this->playerSession();
        $played = getPlayerStatus($this->terminal['NAME']);
        if (!is_array($played) || !isset($played['state'])) {
            return null;
        }
        if ($played['state'] != 'playing') {
            return false;
        }
        $file = $this->currentFile($played);
        if ($file == '') {
            return null;
        }
        return (bool)$this->isMessageFile($file);
    }

    // Файл сообщения, а не музыка: голос из cms/cached/voice, звук из cms/sounds или голосовое
    // сообщение со страницы «Сообщения» (cms/cached/terminals)
    protected function isMessageFile($file)
    {
        return (bool)preg_match('/cms[\\\\\/](cached[\\\\\/]voice|cached[\\\\\/]terminals|sounds)[\\\\\/]/i', (string)$file);
    }

    // Текущий файл плеера из ответа getPlayerStatus()
    protected function currentFile($played)
    {
        if ($this->playerType() == 'mpd') {
            // статус app_player/mpd: track_id — позиция трека, элементы плейлиста идут по порядку
            $position = isset($played['track_id']) ? (int)$played['track_id'] : -1;
            return ($position >= 0 && isset($played[$position]['file'])) ? $played[$position]['file'] : '';
        }
        if (!empty($played['file'])) {
            return $played['file'];
        }
        if (isset($played['track_id'])) {
            // часть плееров (vlcweb) отдаёт файл только в плейлисте: ищем текущий трек по track_id
            foreach ($played as $key => $item) {
                if (is_int($key) && is_array($item) && isset($item['id'], $item['file']) && $item['id'] == $played['track_id']) {
                    return $item['file'];
                }
            }
        }
        return '';
    }

    // Вернуть файл, позицию и громкость после сообщений
    public function restoreState($state)
    {
        if (empty($state['file'])) {
            // музыка не играла — вернуть только громкость
            if (!empty($state['volume'])) {
                $this->playerSession();
                setPlayerVolume($this->terminal['TITLE'], (int)$state['volume']);
                return true;
            }
            return false;
        }
        if ($this->playerType() == 'chromecast') {
            return $this->restoreChromecast($state);
        }
        $title = $this->terminal['TITLE'];
        $this->playerSession();
        // Команды плееру — напрямую, а не через playMedia(): тот отправляет команду в фоне,
        // и перемотка могла бы прийти в плеер раньше запуска.
        // Если прежний трек остался в плейлисте под тем же номером (vlcweb добавляет сообщение
        // в плейлист), включаем его через pl_play: плейлист и порядок треков сохраняются.
        // Иначе (mpd, lms заменяют плейлист; плеер без плейлиста) — запуск файла, как раньше.
        $restored = false;
        if (isset($state['track_id']) && $this->playlistItem((int)$state['track_id'], $state['file'])) {
            $restored = $this->playerCommand('pl_play', (int)$state['track_id']);
        }
        if (!$restored) {
            $this->playerCommand('play', $state['file']);
        }
        if (!empty($state['time'])) {
            seekPlayerPosition($title, (int)$state['time']);
            // плеер мог ещё не загрузить трек: проверяем и при необходимости повторяем перемотку через 2 с
            $now = getPlayerStatus($this->terminal['NAME']);
            if (!is_array($now) || !isset($now['time']) || abs((int)$now['time'] - (int)$state['time']) > 5) {
                // в задании нет сессии, а app_player пишет в $session->data (PHP 8 без неё падает)
                setTimeOut('terminals_restore_seek_' . (int)$this->terminal['ID'],
                    'global $session; if (!is_object($session)) { $session = new stdClass(); $session->data = array(); } '
                    . 'seekPlayerPosition(' . var_export($title, true) . ', ' . (int)$state['time'] . ');', 2);
            }
        }
        if (!empty($state['volume'])) {
            setPlayerVolume($title, (int)$state['volume']);
        }
        $this->playlistCleanup();
        return true;
    }

    // Команда плееру через app_player с ожиданием ответа. Возвращает успех
    protected function playerCommand($command, $param = '')
    {
        include_once(DIR_MODULES . 'app_player/app_player.class.php');
        $player = new app_player();
        $player->play_terminal = $this->terminal['NAME'];
        $player->command = $command;
        $player->param = $param;
        $player->ajax = TRUE;
        $player->intCall = TRUE;
        $player->usual($out);
        return !empty($player->json['success']);
    }

    // Плейлист плеера: массив элементов id/name/file или пустой массив
    protected function playlist()
    {
        include_once(DIR_MODULES . 'app_player/app_player.class.php');
        $player = new app_player();
        $player->play_terminal = $this->terminal['NAME'];
        $player->command = 'pl_get';
        $player->ajax = TRUE;
        $player->intCall = TRUE;
        $player->usual($out);
        if (empty($player->json['success']) || !is_array($player->json['data'])) {
            return array();
        }
        return $player->json['data'];
    }

    // Есть ли в плейлисте ровно один трек с этим номером, и это этот файл
    protected function playlistItem($id, $file)
    {
        $found = 0;
        $same = false;
        foreach ($this->playlist() as $item) {
            if (is_array($item) && isset($item['id'], $item['file']) && (int)$item['id'] == $id) {
                $found++;
                $same = ($item['file'] == $file);
            }
        }
        return $found == 1 && $same;
    }

    // Добавить сообщение в конец плейлиста и включить его по номеру, не трогая остальные треки.
    // Для плееров, у которых play() заменяет плейлист (mpd, lms, kodi). Возвращает успех
    protected function playInPlaylist($url)
    {
        $this->applyMessageVolume();
        $this->playerSession();
        // прежние сообщения уже прозвучали — убираем их, чтобы плейлист не рос
        $this->playlistCleanup();
        if (!$this->playerCommand('pl_add', $url)) {
            return false;
        }
        $id = -1;
        foreach ($this->playlist() as $item) {
            if (is_array($item) && isset($item['id'], $item['file']) && $item['file'] == $url) {
                $id = (int)$item['id']; // последнее совпадение — только что добавленное
            }
        }
        // номер должен однозначно указывать на сообщение, иначе можно включить чужой трек
        if ($id < 0 || !$this->playlistItem($id, $url)) {
            return false;
        }
        return $this->playerCommand('pl_play', $id);
    }

    // Убрать из плейлиста файлы сообщений (cms/cached/voice, cms/sounds); остальные треки не трогаются
    protected function playlistCleanup()
    {
        foreach ($this->playlist() as $item) {
            if (is_array($item) && isset($item['id'], $item['file']) && $this->isMessageFile($item['file'])) {
                $this->playerCommand('pl_delete', (int)$item['id']);
            }
        }
    }

    // Chromecast: в app_player/chromecast нет перемотки — прежний трек загружается сразу с позиции
    // через ту же библиотеку (modules/app_player/libs/castv2)
    protected function restoreChromecast($state)
    {
        include_once(DIR_MODULES . 'app_player/libs/castv2/Chromecast.php');
        $port = empty($this->terminal['PLAYER_PORT']) ? 8009 : $this->terminal['PLAYER_PORT'];
        try {
            $cc = new GChromecast($this->terminal['HOST'], $port);
            $cc->requestId = time();
            $cc->load($state['file'], !empty($state['time']) ? (int)$state['time'] : 0);
            $cc->play();
        } catch (Exception $e) {
            DebMes('Chromecast restore error: ' . $e->getMessage(), 'terminals');
            return false;
        }
        if (!empty($state['volume'])) {
            $this->playerSession();
            setPlayerVolume($this->terminal['TITLE'], (int)$state['volume']);
        }
        return true;
    }

    // DLNA: wav — в mp3 рядом с исходным файлом (как say_message() в app_player/dnla); не вышло — исходный файл
    protected function dlnaFile($file)
    {
        if (strtolower(pathinfo((string)$file, PATHINFO_EXTENSION)) != 'wav') {
            return $file;
        }
        if (!is_file($file) && is_file(ROOT . ltrim((string)$file, '/'))) {
            $file = ROOT . ltrim((string)$file, '/'); // путь от веб-корня (звук из «Сообщений»)
        }
        $mp3 = preg_replace('/\.wav$/i', '.mp3', $file);
        if (!file_exists($mp3)) {
            if (!defined('PATH_TO_FFMPEG')) {
                if (IsWindowsOS()) {
                    define("PATH_TO_FFMPEG", SERVER_ROOT . '/apps/ffmpeg/ffmpeg.exe');
                } else {
                    define("PATH_TO_FFMPEG", 'ffmpeg');
                }
            }
            shell_exec(PATH_TO_FFMPEG . " -i " . escapeshellarg($file) . " -acodec libmp3lame -ar 44100 " . escapeshellarg($mp3) . " 2>&1");
        }
        if (file_exists($mp3)) {
            return $mp3;
        }
        DebMes('DLNA: could not convert to mp3: ' . $file, 'terminals');
        return $file;
    }

    // app_player::usual() пишет в $session->data. В заданиях и фоновых событиях (objects/?job=…)
    // сессии нет: PHP 7 сам создавал пустой объект, PHP 8 падает с ошибкой. Делаем то же, что PHP 7.
    protected function playerSession()
    {
        global $session;
        if (!is_object($session)) {
            $session = new stdClass();
            $session->data = array();
        }
    }
}
