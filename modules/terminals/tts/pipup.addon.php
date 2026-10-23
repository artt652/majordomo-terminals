<?php

include_once(DIR_MODULES . 'terminals/terminals_settings.inc.php');

class pipup extends tts_addon
{
    // Собственное свойство: в базовом tts_addon $title объявлен private,
    // запись в него из наследника создаёт динамическое свойство (Deprecated в PHP 8.2+).
    public $title = 'PiPup';
    // Описание типа в карточке терминала (задаётся в конструкторе — перевод)
    public $description = '';

    // Порт встроенного веб-сервера PiPup по умолчанию
    const DEFAULT_PORT = 7979;
    // Экран, разбуженный перед сообщением, выключается после серии, только если с пробуждения прошло
    // не больше этого (иначе экраном, скорее всего, уже пользуются)
    const SCREEN_SLEEP_WITHIN = 900;

    // Ответ GET /state за время этого объекта (запрос к устройству — один раз)
    private $deviceStateCache = null;

    function __construct($terminal)
    {
        $this->title = 'PiPup';
        $this->description = terminalsLang('TERMINALS_DESC_PIPUP') . ' ' . terminalsLang('TERMINALS_DESC_SIGNAL');
        parent::__construct($terminal);
    }

    // Поля карточки терминала для этого типа TTS (modules/terminals/terminals_settings.inc.php)
    function settingsFields()
    {
        $voices = array('' => terminalsLang('TERMINALS_VOICE_ANY'));
        foreach (self::voiceLabels() as $voice) {
            $voices[$voice['label']] = $voice['title'] . ' — ' . $voice['count'] . ' ' . terminalsLang('TERMINALS_IN_CACHE');
        }
        $voices['device'] = terminalsLang('TERMINALS_VOICE_DEVICE');
        return array(
            array('NAME' => 'PORT', 'TITLE' => terminalsLang('TERMINALS_PIPUP_PORT'), 'HINT' => terminalsLang('TERMINALS_PIPUP_PORT_HINT')),
            array('NAME' => 'VOLUME', 'TITLE' => terminalsLang('TERMINALS_MESSAGE_VOLUME'), 'TYPE' => 'number',
                'HINT' => terminalsLang('TERMINALS_PIPUP_VOLUME_HINT')),
            // по умолчанию — прежнее место настройки, «Имя пользователя» плеера
            array('NAME' => 'VOICE', 'TITLE' => terminalsLang('TERMINALS_VOICE'), 'TYPE' => 'combo', 'OPTIONS' => $voices,
                'DEFAULT' => isset($this->terminal['PLAYER_USERNAME']) ? (string)$this->terminal['PLAYER_USERNAME'] : '',
                'HINT' => terminalsLang('TERMINALS_VOICE_HINT')),
            terminalsSignalField(),
            // экран (форк PiPup >= 0.28: POST /power, GET /state)
            array('NAME' => 'WAKE_LEVEL', 'TITLE' => terminalsLang('TERMINALS_PIPUP_WAKE_LEVEL'), 'TYPE' => 'number',
                'HINT' => terminalsLang('TERMINALS_PIPUP_WAKE_LEVEL_HINT')),
            array('NAME' => 'SLEEP_AFTER', 'TITLE' => terminalsLang('TERMINALS_PIPUP_SLEEP_AFTER'), 'TYPE' => 'checkbox'),
            // камеры go2rtc (terminals_go2rtc.inc.php): RTSP — плеером ExoPlayer; WebRTC — меньше задержка,
            // нужен форк 0.25+, согласование — через сервер MajorDoMo
            array('NAME' => 'CAMERA_FORMAT', 'TITLE' => terminalsLang('TERMINALS_PIPUP_CAMERA_FORMAT'), 'TYPE' => 'select',
                'OPTIONS' => array('rtsp' => 'RTSP', 'webrtc' => terminalsLang('TERMINALS_PIPUP_CAMERA_WEBRTC'))),
        );
    }

    // Как показывать камеры go2rtc: rtsp или webrtc (поле «Камеры»)
    function cameraFormat()
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'pipup') : array();
        return (isset($settings['CAMERA_FORMAT']) && $settings['CAMERA_FORMAT'] == 'webrtc') ? 'webrtc' : 'rtsp';
    }

    // --- Экран устройства (форк PiPup >= 0.28) ---

    // Состояние устройства (GET /state) или null — нет связи или старый PiPup
    function deviceState($fresh = false)
    {
        if ($this->deviceStateCache !== null && !$fresh) {
            return $this->deviceStateCache ?: null;
        }
        $this->deviceStateCache = false;
        $url = $this->notifyUrl('/state');
        if ($url === false) {
            return null;
        }
        $ch = curl_init($url);
        $body = '';
        $httpCode = $this->doRequest($ch, $error, 3, $body);
        $data = ($httpCode === 200) ? json_decode($body, true) : null;
        if (!is_array($data) || !array_key_exists('screenOn', $data)) {
            return null;
        }
        $this->deviceStateCache = $data;
        return $data;
    }

    // Экран: null — неизвестно; иначе visible (включён и не заставка), on, dreaming, can_wake, can_sleep
    function screenState($fresh = false)
    {
        $state = $this->deviceState($fresh);
        if ($state === null) {
            return null;
        }
        $power = (isset($state['power']) && is_array($state['power'])) ? $state['power'] : array();
        $on = !empty($state['screenOn']);
        $dreaming = !empty($state['dreaming']);
        return array(
            'visible' => $on && !$dreaming,
            'on' => $on,
            'dreaming' => $dreaming,
            'can_wake' => !isset($power['canWake']) || !empty($power['canWake']),
            'can_sleep' => !empty($power['canSleep']),
        );
    }

    // Включить ('on'), выключить ('off') или переключить ('toggle') экран: POST /power?state=…
    // Выключение работает, если PiPup выданы права администратора устройства или спец. возможностей
    function screen($state = 'on')
    {
        if (!in_array($state, array('on', 'off', 'toggle'), true)) {
            return 0;
        }
        $url = $this->notifyUrl('/power?state=' . $state);
        if ($url === false) {
            return 0;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        $httpCode = $this->doRequest($ch, $error, 4);
        $this->deviceStateCache = null;
        if ($httpCode !== 200) {
            DebMes("PiPup: screen $state at $url failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }
        DebMes("PiPup: screen $state at $url", 'terminals');
        return 1;
    }

    // Разбудить экран перед сообщением, если уровень не ниже «Будить экран с уровня» и экран не виден
    private function wakeFor($level)
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'pipup') : array();
        $wake = isset($settings['WAKE_LEVEL']) ? trim((string)$settings['WAKE_LEVEL']) : '';
        if ($wake === '' || !is_numeric($wake) || (int)$level < (int)$wake) {
            return;
        }
        $screen = $this->screenState();
        if ($screen === null || $screen['visible'] || !$screen['can_wake']) {
            return;
        }
        if ($this->screen('on') && !empty($this->terminal['ID']) && function_exists('terminalsQueueStateMark')) {
            // для «Выключать экран после сообщений»: экран будил модуль
            terminalsQueueStateMark((int)$this->terminal['ID'], 'screen_woke', time());
        }
    }

    // Очередь: перед первым сообщением серии — был ли экран выключен (только с «Выключать экран после»)
    function saveState()
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'pipup') : array();
        if (empty($settings['SLEEP_AFTER'])) {
            return array();
        }
        $screen = $this->screenState();
        return ($screen !== null && !$screen['visible']) ? array('screen_off' => 1) : array();
    }

    // Очередь: после последнего сообщения серии — выключить экран, если его будил модуль
    function restoreState($state)
    {
        if (empty($state['screen_off']) || empty($state['screen_woke']) || time() - (int)$state['screen_woke'] > self::SCREEN_SLEEP_WITHIN) {
            return;
        }
        $screen = $this->screenState(true);
        if ($screen !== null && $screen['visible'] && $screen['can_sleep']) {
            $this->screen('off');
        }
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
            'yandex' => terminalsLang('TERMINALS_YANDEX'), 'google' => 'Google');
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

    // Длительность звука или видео в целых секундах (вверх), 0 — не узнали (terminals_media.inc.php)
    function mediaSeconds($file)
    {
        include_once DIR_MODULES . 'terminals/terminals_media.inc.php';
        return (int)ceil(terminalsMediaDuration($file));
    }

    // Сообщения идут через очередь модуля terminals: окна не перебивают друг друга
    function useQueue()
    {
        return true;
    }

    function say($phrase, $level = 0)
    {
        $this->wakeFor($level);
        return $this->sendCommand($phrase, terminalsLang('TERMINALS_NOTIFICATION'), $this->messageId($phrase), $this->deviceVoice());
    }

    // Сообщение с картинкой или от другого терминала (модуль terminals вызывает его вместо
    // say()/sayCached(), если он есть): текст, картинка и, если уже готов, звук голоса — в одном окне.
    // Картинка — путь в веб-корне MajorDoMo (как в say('… image:cms/…')) или http-ссылка.
    // $sender — от кого, пишется в заголовке окна
    function showMessage($phrase, $level = 0, $image = '', $cached_file = '', $sender = '')
    {
        $this->wakeFor($level);
        $title = ($sender != '') ? terminalsLang('TERMINALS_FROM_PREFIX') . ': ' . $sender : terminalsLang('TERMINALS_NOTIFICATION');
        $imgUrl = ($image != '') ? $this->fileUrl($image) : false;
        if ($image != '' && $imgUrl === false) {
            DebMes("PiPup: image not found: $image", 'terminals');
        }
        if ($imgUrl === false) {
            if ($cached_file != '') {
                return ($this->deviceVoice() || !$this->engineAllowed($cached_file)) ? 0
                    : $this->soundMessage($phrase, $cached_file, '🔊 ' . $title);
            }
            return $this->sendCommand($phrase, $title, $this->messageId($phrase), $this->deviceVoice());
        }
        $url = $this->notifyUrl('/notify');
        if ($url === false) {
            return 0;
        }
        $data = array(
            'duration' => $this->autoDuration($phrase),
            'position' => 0,
            'title' => $title,
            'titleColor' => '#0066cc',
            'titleSize' => 20,
            'message' => (string)$phrase,
            'messageColor' => '#000000',
            'messageSize' => 14,
            'backgroundColor' => '#ffffff',
            'media' => array('image' => array('uri' => $imgUrl, 'width' => 480)),
        );
        $forkFields = array(
            'id' => $this->messageId($phrase), // то же окно, что у say()/sayCached() этой фразы
            'stack' => 'vertical',
        );
        if ($this->deviceVoice()) {
            $forkFields['tts'] = (string)$phrase;
            if (defined('SETTINGS_SITE_LANGUAGE') && SETTINGS_SITE_LANGUAGE != '') {
                $forkFields['ttsLanguage'] = SETTINGS_SITE_LANGUAGE;
            }
        } elseif ($cached_file != '' && $this->engineAllowed($cached_file)) {
            $soundUrl = $this->fileUrl($cached_file);
            if ($soundUrl !== false) {
                $data['sound'] = $soundUrl;
                if ($this->messageVolume() !== null) {
                    $data['soundVolume'] = $this->messageVolume();
                }
                $local = $this->localPath($cached_file);
                if ($local !== false && ($seconds = $this->mediaSeconds($local)) > 0) {
                    $data['duration'] = max($data['duration'], $seconds + 2);
                }
            }
        }
        DebMes("Sending message with image '$image' to " . $url, 'terminals');
        $httpCode = $this->post($url, array_merge($data, $forkFields), $error);
        if ($httpCode === 400) {
            $httpCode = $this->post($url, $data, $error);
        }
        if ($httpCode !== 200) {
            DebMes("PiPup: message with image to $url failed (HTTP $httpCode) $error", 'terminals');
            return 0;
        }
        return 1;
    }

    function ask($phrase, $level = 0)
    {
        $this->wakeFor(max((int)$level, 9999));
        return $this->sendCommand($phrase, 'Вопрос', $this->messageId($phrase), $this->deviceVoice());
    }

    /**
     * Окно с текстом, который произносит синтезатор речи самого устройства (поле tts PiPup).
     * Для вызова из сценариев, независимо от настройки терминала.
     */
    function sayDevice($phrase, $title = '', $duration = 0)
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
        return $this->soundMessage($phrase, $cached_file, '🔊 ' . terminalsLang('TERMINALS_NOTIFICATION'));
    }

    // Звуковой файл (голосовое, музыка или звук перед сообщением из terminalsSend, сигнал очереди) — отдельным окном.
    // Файл — путь в веб-корне MajorDoMo или http-ссылка; $title — заголовок окна (например, отправитель);
    // $known — длительность, если известна заранее (запись из браузера, в файле её нет): окно не закроется раньше
    function playFile($file, $level = 0, $title = '', $known = 0)
    {
        $this->wakeFor($level);
        $soundUrl = $this->fileUrl($file);
        if ($soundUrl === false) {
            DebMes("PiPup: sound not found: $file", 'terminals');
            return 0;
        }
        $duration = 5;
        $local = $this->localPath($file);
        if ($known > 0) {
            $duration = max(3, (int)ceil($known) + 2);
        } elseif ($local !== false && ($seconds = $this->mediaSeconds($local)) > 0) {
            $duration = max(3, $seconds + 2);
        }
        return $this->sendSound($soundUrl, '', ($title != '') ? '🔊 ' . $title : '🔊', $duration, $this->messageVolume());
    }

    // «Громкость сообщений» (1–100) — громкость звука окна относительно громкости устройства (0..1) или null
    private function messageVolume()
    {
        $settings = function_exists('terminalsAddonSettings') ? terminalsAddonSettings($this->terminal, 'tts', 'pipup') : array();
        return (isset($settings['VOLUME']) && (int)$settings['VOLUME'] > 0) ? min(100, (int)$settings['VOLUME']) / 100 : null;
    }

    // Окно с текстом фразы и её голосом
    private function soundMessage($phrase, $cached_file, $title)
    {
        // Выбор генератора голоса: поле «Голос сообщений» в настройках TTS (раньше — «Имя пользователя» плеера).
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
        if ($local !== false && ($seconds = $this->mediaSeconds($local)) > 0) {
            $duration = max($duration, $seconds + 2);
        }
        return $this->sendSound($soundUrl, $phrase, $title, $duration, $this->messageVolume(), $this->messageId($phrase));
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

    function sendCommand($phrase, $title = '', $id = '', $deviceTts = false, $duration = 0)
    {
        if ($title == '') {
            $title = terminalsLang('TERMINALS_NOTIFICATION');
        }
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
            $title = terminalsLang('TERMINALS_NOTIFICATION');
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
     * Используется плеером app_player «pipup_play» и сценариями. $volume — 0..1 или null.
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
        return $this->sendVideo($page . '?src=' . rawurlencode($src), $text, ($title == '') ? terminalsLang('TERMINALS_NOTIFICATION') : $title, 'web', $options);
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

    // ip:порт PiPup. «Адрес» терминала — только IP или имя устройства (порт, если вписан, отбрасывается)
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

    private function doRequest($ch, &$error, $timeout, &$body = null)
    {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);

        $result = curl_exec($ch);
        $body = is_string($result) ? $result : '';
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
