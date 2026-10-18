<?php

include_once(DIR_MODULES . 'terminals/terminals_settings.inc.php');

class pipup extends tts_addon
{
    // Собственное свойство: в базовом tts_addon $title объявлен private,
    // запись в него из наследника создаёт динамическое свойство (Deprecated в PHP 8.2+).
    public $title = 'PiPup';

    // Порт встроенного веб-сервера PiPup по умолчанию
    const DEFAULT_PORT = 7979;

    function __construct($terminal)
    {
        $this->title = 'PiPup';
        parent::__construct($terminal);
    }

    // Поля карточки терминала для этого типа TTS (modules/terminals/terminals_settings.inc.php)
    function settingsFields()
    {
        $voices = array('' => 'Любой голос — первый готовый файл генератора');
        foreach (self::voiceLabels() as $voice) {
            $voices[$voice['label']] = $voice['title'] . ' — ' . $voice['count'] . ' в кеше';
        }
        $voices['device'] = 'Голос устройства (device)';
        return array(
            array('NAME' => 'PORT', 'TITLE' => 'Порт PiPup', 'HINT' => 'пусто — как у плеера, иначе 7979'),
            // по умолчанию — прежнее место настройки, «Имя пользователя» плеера
            array('NAME' => 'VOICE', 'TITLE' => 'Голос сообщений', 'TYPE' => 'combo', 'OPTIONS' => $voices,
                'DEFAULT' => isset($this->terminal['PLAYER_USERNAME']) ? (string)$this->terminal['PLAYER_USERNAME'] : '',
                'HINT' => 'пусто — любой голос; sanotts, yandex… — только эти; device — голос устройства'),
        );
    }

    // Значение поля «Голос сообщений». Пока поля TTS не сохраняли — прежнее место, «Имя пользователя» плеера
    private function voiceSetting()
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'pipup') : array();
        if (array_key_exists('VOICE', $settings)) {
            return (string)$settings['VOICE'];
        }
        return isset($this->terminal['PLAYER_USERNAME']) ? (string)$this->terminal['PLAYER_USERNAME'] : '';
    }

    // Генераторы речи, чьи файлы есть в кеше cms/cached/voice (и его подпапках): метка → число файлов.
    // Метка — то, что остаётся от имени файла без md5: префикс (sanotts_<md5>) или суффикс (<md5>_yandex).
    private static function voiceLabels()
    {
        $names = array('sanotts' => 'sanoTTS', 'piper_tts' => 'Piper', 'sapi' => 'Windows SAPI',
            'yandex' => 'Яндекс', 'google' => 'Google');
        // Служебные файлы генераторов с другой меткой: piper_dict_<md5> — прослушивание фраз
        // в словаре piper_tts (кнопка ▶). Это тот же голос, считаем их вместе с piper_tts.
        $aliases = array('piper_dict' => 'piper_tts');
        $dir = ROOT . 'cms/cached/voice';
        $counts = array();
        $left = 20000; // не читать бесконечно большой кеш
        $stack = array($dir);
        while ($stack && $left > 0) {
            $d = array_pop($stack);
            $list = @scandir($d);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $f) {
                if ($f == '.' || $f == '..' || --$left <= 0) {
                    continue;
                }
                $path = $d . DIRECTORY_SEPARATOR . $f;
                if (is_dir($path)) {
                    if ($d == $dir) {
                        $stack[] = $path; // подпапки первого уровня
                    }
                    continue;
                }
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext, array('mp3', 'wav', 'ogg', 'opus', 'm4a', 'aac', 'flac'), true)) {
                    continue;
                }
                $name = strtolower(pathinfo($f, PATHINFO_FILENAME));
                if (!preg_match('/^(?:([a-z0-9_]+?)_)?[0-9a-f]{32}(?:_([a-z0-9_]+))?$/', $name, $m)) {
                    continue;
                }
                $label = !empty($m[1]) ? $m[1] : (isset($m[2]) ? $m[2] : '');
                if (isset($aliases[$label])) {
                    $label = $aliases[$label];
                }
                if ($label !== '') {
                    $counts[$label] = isset($counts[$label]) ? $counts[$label] + 1 : 1;
                }
            }
        }
        arsort($counts);
        $out = array();
        foreach ($counts as $label => $count) {
            $out[] = array('label' => $label, 'title' => isset($names[$label]) ? $names[$label] . ' (' . $label . ')' : $label, 'count' => $count);
        }
        return $out;
    }

    // Сообщения идут через очередь модуля terminals: окна не перебивают друг друга
    function useQueue()
    {
        return true;
    }

    function say($phrase, $level = 0)
    {
        return $this->sendCommand($phrase, 'Уведомление', $this->messageId($phrase), $this->deviceVoice());
    }

    function ask($phrase, $level = 0)
    {
        return $this->sendCommand($phrase, 'Вопрос', $this->messageId($phrase), $this->deviceVoice());
    }

    /**
     * Окно с текстом, который произносит синтезатор речи самого устройства (поле tts PiPup).
     * Для вызова из сценариев, независимо от настройки терминала.
     */
    function sayDevice($phrase, $title = 'Уведомление', $duration = 0)
    {
        return $this->sendCommand($phrase, $title, $this->messageId($phrase), true, $duration);
    }

    /**
     * Длительность окна по длине текста: 10 с + 1 с на каждые 15 символов, не больше 60 с.
     */
    public function autoDuration($text)
    {
        $text = (string)$text;
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        return (int)min(60, 10 + ceil($len / 15));
    }

    /**
     * Готовый аудиофайл от генератора речи (SAY_CACHED_READY → terminals → sayCached).
     * То же окно, что открыл say() (тот же id), перерисовывается со значком 🔊 и звуком.
     */
    function sayCached($phrase, $level = 0, $cached_file = '')
    {
        // Выбор генератора голоса: поле «Имя пользователя» плеера в карточке терминала.
        // Значение — метка генератора в имени файла: префикс (sanotts, piper_tts, rh, sapi)
        // или суффикс (yandex, google, ivona, tilde); можно несколько через запятую.
        // device — говорить синтезатором самого устройства, файлы генераторов не использовать.
        // Пусто — принимать файлы любого генератора.
        if ($this->deviceVoice()) {
            // Терминал говорит голосом устройства — файлы генераторов не нужны
            return 0;
        }
        if (!$this->engineAllowed($cached_file)) {
            DebMes('PiPup: skip ' . basename((string)$cached_file) . ' (terminal voice: ' . $this->voiceSetting() . ')', 'terminals');
            return 0;
        }
        $soundUrl = $this->fileUrl($cached_file);
        if ($soundUrl === false) {
            DebMes("PiPup: cannot build URL for cached file '$cached_file'", 'terminals');
            return 0;
        }
        // Окно висит не меньше, чем нужно на чтение текста, и не меньше длины звука + 2 с
        $duration = $this->autoDuration($phrase);
        $local = $this->localPath($cached_file);
        if ($local !== false && function_exists('getMediaDurationSeconds')) {
            $duration = max($duration, (int)getMediaDurationSeconds($local) + 2);
        }
        return $this->sendSound($soundUrl, $phrase, '🔊 Уведомление', $duration, null, $this->messageId($phrase));
    }

    // Голос устройства: в поле «Голос сообщений» указано device
    private function deviceVoice()
    {
        $filter = strtolower($this->voiceSetting());
        foreach (explode(',', $filter) as $engine) {
            if (trim($engine) === 'device') {
                return true;
            }
        }
        return false;
    }

    // Подходит ли файл генератору, выбранному для терминала
    private function engineAllowed($cached_file)
    {
        $filter = trim($this->voiceSetting());
        if ($filter === '') {
            return true;
        }
        // Имя файла без расширения. Генераторы метят файлы по-разному:
        // префиксом (sanotts_<md5>, piper_tts_<md5>, rh_<md5>, sapi_<md5>)
        // или суффиксом (<md5>_yandex, <md5>_google, <md5>_ivona, <md5>_tilde).
        $name = strtolower(pathinfo((string)$cached_file, PATHINFO_FILENAME));
        foreach (explode(',', $filter) as $engine) {
            $engine = strtolower(trim($engine));
            if ($engine === '') {
                continue;
            }
            $tail = '_' . $engine;
            if (strpos($name, $engine . '_') === 0
                || (strlen($name) > strlen($tail) && substr($name, -strlen($tail)) === $tail)) {
                return true;
            }
        }
        return false;
    }

    // Один id для say() и sayCached() одной фразы: окно одно, звук «догоняет» текст
    private function messageId($phrase)
    {
        $tid = isset($this->terminal['ID']) ? $this->terminal['ID'] : '';
        return 'mdm_' . md5($tid . '|' . (string)$phrase);
    }

    function sendCommand($phrase, $title = 'Уведомление', $id = '', $deviceTts = false, $duration = 0)
    {
        if (empty($this->terminal['HOST'])) {
            return 0;
        }
        if (!function_exists('curl_init')) {
            DebMes('PiPup: PHP extension curl is not available', 'terminals');
            return 0;
        }

        $host = $this->hostPort();
        $url = 'http://' . $host . '/notify';

        DebMes("Sending notification '$phrase' to " . $host, 'terminals');

        $data = array(
            'duration' => ((int)$duration > 0) ? (int)$duration : $this->autoDuration($phrase),
            'position' => 0,
            'title' => $title,
            'titleColor' => '#0066cc',
            'titleSize' => 20,
            'message' => (string)$phrase,
            'messageColor' => '#000000',
            'messageSize' => 14,
            'backgroundColor' => '#ffffff',
        );

        // Форк PiPup (mhoogenbosch): уведомления без id занимают один слот и заменяют друг друга.
        // Уникальный id — отдельное окно, stack — выстраивать окна в столбик, а не друг на друга.
        $forkFields = array(
            'id' => ($id != '') ? (string)$id : 'mdm_' . str_replace('.', '_', uniqid('', true)),
            'stack' => 'vertical',
        );
        if ($deviceTts) {
            // Произнести текст синтезатором устройства (только форк PiPup).
            // Язык — язык сайта MajorDoMo; без него устройство берёт свой язык по умолчанию.
            $forkFields['tts'] = (string)$phrase;
            if (defined('SETTINGS_SITE_LANGUAGE') && SETTINGS_SITE_LANGUAGE != '') {
                $forkFields['ttsLanguage'] = SETTINGS_SITE_LANGUAGE;
            }
        }

        $httpCode = $this->post($url, array_merge($data, $forkFields), $error);
        if ($httpCode === 400) {
            // Оригинальный PiPup не знает полей id/stack и отвечает 400 — повторяем без них.
            $httpCode = $this->post($url, $data, $error);
        }

        if ($httpCode !== 200) {
            DebMes("PiPup: notify to $host failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }

        return 1;
    }

    /**
     * Показать картинку (для вызова из сценариев).
     * $image — URL (http/https, телевизор должен до него достучаться)
     *          или локальный путь к файлу (отправляется файлом через multipart).
     */
    function sendImage($image, $text = '', $title = '', $width = 480, $duration = 30)
    {
        if (empty($this->terminal['HOST']) || $image == '') {
            return 0;
        }
        if (!function_exists('curl_init')) {
            DebMes('PiPup: PHP extension curl is not available', 'terminals');
            return 0;
        }
        $host = $this->hostPort();
        $url = 'http://' . $host . '/notify';
        if ($title == '') {
            $title = 'Уведомление';
        }

        $data = array(
            'duration' => max(1, (int)$duration),
            'position' => 0,
            'title' => $title,
            'titleColor' => '#0066cc',
            'titleSize' => 20,
            'message' => (string)$text,
            'messageColor' => '#000000',
            'messageSize' => 14,
            'backgroundColor' => '#ffffff',
        );
        $forkFields = array(
            'id' => 'mdm_' . str_replace('.', '_', uniqid('', true)),
            'stack' => 'vertical',
        );

        if (preg_match('/^https?:\/\//i', $image)) {
            $data['media'] = array('image' => array('uri' => $image, 'width' => (int)$width));
            $multipart = false;
        } elseif (is_file($image) && is_readable($image)) {
            if (!class_exists('CURLFile')) {
                DebMes('PiPup: CURLFile is not available', 'terminals');
                return 0;
            }
            $data['image'] = new CURLFile($image);
            $data['imageWidth'] = (int)$width;
            $multipart = true;
        } else {
            DebMes("PiPup: image not found: $image", 'terminals');
            return 0;
        }

        DebMes("Sending image '$image' to " . $host, 'terminals');

        $httpCode = $this->post($url, array_merge($data, $forkFields), $error, $multipart);
        if ($httpCode === 400) {
            // Оригинальный PiPup не знает полей id/stack — повторяем без них.
            $httpCode = $this->post($url, $data, $error, $multipart);
        }
        if ($httpCode !== 200) {
            DebMes("PiPup: image to $host failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }
        return 1;
    }

    /**
     * Показать видео (для вызова из сценариев). Видео передаётся только ссылкой,
     * телевизор подключается к источнику сам.
     * $type:    'video' — RTSP/HLS/MP4 (ExoPlayer), 'web' — веб-страница/MJPEG,
     *           'whep' — WebRTC из go2rtc (/api/webrtc?src=...), только форк >= 0.25
     * $options: width, height, poster (URL снимка до первого кадра), muted (по умолчанию true),
     *           duration (0 — пока не вызовут cancel()), id (тот же id — обновить окно на месте),
     *           liveBadge (true — плашка «Live» поверх видео), liveBadgeColor (#RRGGBB),
     *           liveBadgePosition (top_left|top_right|bottom_left|bottom_right)
     * Поля poster/whep/height/liveBadge* понимает только форк PiPup.
     */
    function sendVideo($uri, $text = '', $title = '', $type = 'video', $options = array())
    {
        if (!in_array($type, array('video', 'web', 'whep'), true) || $uri == '') {
            DebMes("PiPup: bad video type or empty uri ($type)", 'terminals');
            return 0;
        }
        if (!is_array($options)) {
            $options = array();
        }
        $url = $this->notifyUrl('/notify');
        if ($url === false) {
            return 0;
        }

        $media = array(
            'uri' => $uri,
            'width' => isset($options['width']) ? (int)$options['width'] : 640,
            'muted' => isset($options['muted']) ? (bool)$options['muted'] : true,
        );
        if (isset($options['height'])) {
            $media['height'] = (int)$options['height'];
        }
        if (!empty($options['poster'])) {
            $media['poster'] = $options['poster'];
        }

        $data = array(
            'duration' => isset($options['duration']) ? (int)$options['duration'] : 30,
            'position' => 0,
            'title' => ($title == '') ? 'Камера' : $title,
            'titleColor' => '#8ab4f8',
            'titleSize' => 20,
            'message' => (string)$text,
            'messageColor' => '#e8eaed',
            'messageSize' => 14,
            // тёмный фон: до первого кадра окно видео не белое, рамка не спорит с картинкой
            'backgroundColor' => '#202124',
            'media' => array($type => $media),
        );
        $forkFields = array(
            'id' => !empty($options['id']) ? (string)$options['id'] : 'mdm_' . str_replace('.', '_', uniqid('', true)),
            'stack' => 'vertical',
        );
        // Плашка «Live» поверх видео (только форк), появляется с первым кадром
        if (!empty($options['liveBadge'])) {
            $forkFields['liveBadge'] = true;
            if (!empty($options['liveBadgeColor'])) {
                $forkFields['liveBadgeColor'] = (string)$options['liveBadgeColor'];
            }
            if (!empty($options['liveBadgePosition'])
                && in_array($options['liveBadgePosition'], array('top_left', 'top_right', 'bottom_left', 'bottom_right'), true)) {
                $forkFields['liveBadgePosition'] = $options['liveBadgePosition'];
            }
        }

        DebMes("Sending $type '$uri' to " . $url, 'terminals');
        $httpCode = $this->post($url, array_merge($data, $forkFields), $error);
        if ($httpCode === 400) {
            $httpCode = $this->post($url, $data, $error);
        }
        if ($httpCode !== 200) {
            DebMes("PiPup: $type to $url failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }
        return 1;
    }

    /**
     * Окно со звуком по ссылке (поле sound, только форк PiPup >= 0.18).
     * Используется плеером app_player «pipup_play». $volume — 0..1 или null.
     */
    function sendSound($soundUrl, $text = '', $title = '', $duration = 15, $volume = null, $id = '')
    {
        $url = $this->notifyUrl('/notify');
        if ($url === false || $soundUrl == '') {
            return 0;
        }
        $data = array(
            'duration' => max(3, (int)$duration),
            'position' => 0,
            'title' => ($title == '') ? '🔊' : $title,
            'titleColor' => '#0066cc',
            'titleSize' => 20,
            'message' => (string)$text,
            'messageColor' => '#000000',
            'messageSize' => 14,
            'backgroundColor' => '#ffffff',
            'sound' => $soundUrl,
        );
        if ($volume !== null) {
            $data['soundVolume'] = max(0, min(1, (float)$volume));
        }
        // По умолчанию id новый: при том же id и том же содержимом PiPup звук не проигрывает
        $forkFields = array(
            'id' => ($id != '') ? (string)$id : 'mdm_' . str_replace('.', '_', uniqid('', true)),
            'stack' => 'vertical',
        );

        DebMes("Sending sound '$soundUrl' to " . $url, 'terminals');
        $httpCode = $this->post($url, array_merge($data, $forkFields), $error);
        if ($httpCode === 400) {
            $httpCode = $this->post($url, $data, $error);
        }
        if ($httpCode !== 200) {
            DebMes("PiPup: sound to $url failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }
        return 1;
    }

    /**
     * Картинка, которую обычный загрузчик PiPup не показывает (SVG, в т.ч. анимированный),
     * через встроенный браузер PiPup. Картинка вписывается в квадрат $size x $size px.
     * $image — локальный путь внутри веб-корня или ссылка http(s).
     */
    function sendWebImage($image, $text = '', $title = '', $size = 220, $duration = 0, $id = '')
    {
        $imgUrl = $this->fileUrl($image);
        if ($imgUrl === false) {
            DebMes("PiPup: image not found: $image", 'terminals');
            return 0;
        }
        $page = $this->viewerPage();
        if ($page === false) {
            return 0;
        }
        // Своя картинка сервера — относительным путём (тот же адрес, что у страницы)
        $src = ($this->localPath($image) !== false) ? parse_url($imgUrl, PHP_URL_PATH) : $imgUrl;
        $options = array(
            'width' => (int)$size,
            'height' => (int)$size,
            'duration' => ((int)$duration > 0) ? (int)$duration : $this->autoDuration($text),
        );
        if ($id != '') {
            $options['id'] = $id;
        }
        return $this->sendVideo($page . '?src=' . rawurlencode($src), $text, ($title == '') ? 'Уведомление' : $title, 'web', $options);
    }

    // Страница-обёртка для sendWebImage(): без отступов, картинка вписана в окно
    private function viewerPage()
    {
        $file = ROOT . 'cms/cached/pipup_view.html';
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;background:#fff}'
            . 'img{display:block;width:100%;height:100%;object-fit:contain}</style></head>'
            . '<body><img id="i" alt=""><script>'
            . 'var s=new URLSearchParams(location.search).get("src")||"";'
            . 'if(/^(\/|https?:\/\/)/.test(s))document.getElementById("i").src=s;'
            . '</script></body></html>';
        if (!is_file($file) || file_get_contents($file) !== $html) {
            if (@file_put_contents($file, $html) === false) {
                DebMes("PiPup: cannot write $file", 'terminals');
                return false;
            }
        }
        return $this->fileUrl($file);
    }

    /**
     * Закрыть все окна PiPup на устройстве (форк >= 0.24; оригинал закрывает текущее окно).
     */
    function cancelAll()
    {
        return $this->cancelRequest('/cancel?all=true');
    }

    /**
     * Закрыть окно сообщения, показанного say()/sayTo()/ask()/sayDevice(), по его тексту.
     */
    function cancelMessage($phrase)
    {
        return $this->cancel($this->messageId($phrase));
    }

    /**
     * Закрыть окно: с $id — только окно с этим id (форк), без $id — окно, отправленное без id.
     */
    function cancel($id = '')
    {
        return $this->cancelRequest('/cancel' . ($id != '' ? '?id=' . rawurlencode($id) : ''));
    }

    private function cancelRequest($path)
    {
        $url = $this->notifyUrl($path);
        if ($url === false) {
            return 0;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        $httpCode = $this->doRequest($ch, $error, 4);
        if ($httpCode !== 200) {
            DebMes("PiPup: cancel $url failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }
        return 1;
    }

    /**
     * Локальный файл внутри веб-корня (realpath) или false.
     */
    public function localPath($input)
    {
        $input = trim((string)$input);
        if ($input === '' || preg_match('/^[a-z][a-z0-9+.\-]*:\/\//i', $input)) {
            return false; // любая ссылка (http, rtsp, ...) — не локальный файл
        }
        $path = $input;
        if (!is_file($path) && is_file(ROOT . ltrim($path, '/'))) {
            $path = ROOT . ltrim($path, '/');
        }
        if (!is_file($path)) {
            return false;
        }
        $real = realpath($path);
        $root = realpath(ROOT);
        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }
        return $real;
    }

    /**
     * URL, по которому устройство заберёт файл (LAN-IP сервера + порт из BASE_URL), или false.
     * Ссылки http(s) возвращаются как есть.
     */
    public function fileUrl($input)
    {
        $input = trim((string)$input);
        if (preg_match('/^https?:\/\//i', $input)) {
            return $input;
        }
        $real = $this->localPath($input);
        if ($real === false) {
            return false;
        }
        $ip = getLocalIp();
        if (!$ip) {
            return false;
        }
        $port = parse_url(BASE_URL, PHP_URL_PORT);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen(realpath(ROOT)) + 1));
        $rel = implode('/', array_map('rawurlencode', explode('/', $rel)));
        return 'http://' . $ip . ($port ? ':' . $port : '') . rtrim(ROOTHTML, '/') . '/' . $rel;
    }

    // ip:порт PiPup. «Адрес» терминала — только IP или имя устройства (порт, если вписан, отбрасывается),
    // порт PiPup — поле «Порт» плеера, пусто — 7979
    private function hostPort()
    {
        $host = preg_replace('/:\d*$/', '', trim($this->terminal['HOST']));
        // «Порт PiPup» в настройках TTS; пусто — порт плеера (pipup_play), иначе 7979
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'pipup') : array();
        $port = !empty($settings['PORT']) ? (int)$settings['PORT'] : 0;
        if ($port <= 0 || $port > 65535) {
            $port = isset($this->terminal['PLAYER_PORT']) ? (int)$this->terminal['PLAYER_PORT'] : 0;
        }
        return $host . ':' . (($port > 0 && $port < 65536) ? $port : self::DEFAULT_PORT);
    }

    // Адрес PiPup для пути $path, false — нет HOST или curl
    private function notifyUrl($path)
    {
        if (empty($this->terminal['HOST'])) {
            return false;
        }
        if (!function_exists('curl_init')) {
            DebMes('PiPup: PHP extension curl is not available', 'terminals');
            return false;
        }
        $host = $this->hostPort();
        return 'http://' . $host . $path;
    }

    // POST JSON или multipart, возвращает HTTP-код (0 — нет соединения)
    private function post($url, $data, &$error, $multipart = false)
    {
        $error = '';
        if ($multipart) {
            // multipart: все поля, кроме файла, передаются строками
            foreach ($data as $k => $v) {
                if (!is_object($v)) {
                    $data[$k] = (string)$v;
                }
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // Content-Type с boundary curl выставит сам
            // NanoHTTPD декодирует текстовые поля multipart в кодировке из заголовка запроса
            // (по умолчанию US-ASCII — отсюда «кракозябры»). boundary curl допишет сам.
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data; charset=UTF-8'));
            return $this->doRequest($ch, $error, 10); // с запасом на передачу файла
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $postData = json_encode($data, $flags);
        if ($postData === false) {
            $error = 'json_encode failed: ' . json_last_error_msg();
            return 0;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($postData),
        ));
        return $this->doRequest($ch, $error, 4);
    }

    private function doRequest($ch, &$error, $timeout)
    {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);

        $result = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false) {
            $error = curl_error($ch);
            $httpCode = 0;
        }
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // с PHP 8.0 не нужен, в PHP 8.5 — Deprecated
        }
        return $httpCode;
    }
}
