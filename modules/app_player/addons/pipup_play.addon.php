<?php

/*
	Addon PiPup (pipup_play) for app_player
	Показывает медиа во всплывающем окне PiPup на Android-устройстве
	(форк mhoogenbosch/PiPup, поле sound). Отправку выполняет дополнение
	терминалов modules/terminals/tts/pipup.addon.php.
*/

class pipup_play extends app_player_addon
{

    // Constructor
    function __construct($terminal)
    {
        include_once(DIR_MODULES . 'terminals/terminals_settings.inc.php');
        $this->title = terminalsLang('TERMINALS_PIPUP_PLAY_TITLE');
        // Коротко — сразу, остальное свёрнуто под «Подробнее»
        $this->description = terminalsLang('TERMINALS_PIPUP_PLAY_DESC')
            // отступы: после «Подробнее» — побольше; у строки «Поддерживаемые функции», которую дописывает ядро, снизу — поменьше
            . '<style>#pipup_play_description > p:last-child{margin-bottom:2px}</style>'
            . '<details style="margin:4px 0 10px"><summary style="cursor:pointer;display:list-item">' . terminalsLang('TERMINALS_MORE') . '</summary>'
            . terminalsLang('TERMINALS_PIPUP_PLAY_MORE')
            . '</details>';
        parent::__construct($terminal);
    }

    // Поля карточки терминала для этого плеера (modules/terminals/terminals_settings.inc.php)
    function settingsFields()
    {
        return array(
            array('NAME' => 'PLAYER_PORT', 'TITLE' => terminalsLang('TERMINALS_PIPUP_PORT'), 'HINT' => terminalsLang('TERMINALS_PIPUP_PLAY_PORT_HINT')),
        );
    }

    // Play: тип окна выбирается по ссылке или расширению файла
    function play($input)
    {
        $this->reset_properties();
        $input = trim((string)$input);
        if ($input === '') {
            $this->success = FALSE;
            $this->message = 'Input is missing!';
            return $this->success;
        }

        $pipup = $this->pipup();
        $type = $this->mediaType($input);

        // Потоки rtsp/rtmp передаются как есть, остальное — через адрес, доступный устройству
        if (preg_match('/^(rtsp|rtsps|rtmp):\/\//i', $input)) {
            $url = $input;
        } else {
            $url = $pipup->fileUrl($input);
        }
        if ($url === false) {
            $this->success = FALSE;
            $this->message = 'Cannot build URL for ' . $input . ' (file must be inside web root, or pass a URL)';
            return $this->success;
        }

        $local = $pipup->localPath($input);
        $seconds = 0;
        if ($local !== false && ($type == 'audio' || $type == 'video') && function_exists('getMediaDurationSeconds')) {
            $seconds = (int)getMediaDurationSeconds($local);
        }

        switch ($type) {
            case 'image':
                // Локальный файл уходит файлом, ссылка — ссылкой
                $ok = $this->send($pipup, 'sendImage', array(($local !== false) ? $local : $url, '', '🖼', 480, 30));
                break;
            case 'svg':
                $ok = $this->send($pipup, 'sendWebImage', array(($local !== false) ? $local : $url, '', '🖼', 300, 30));
                break;
            case 'video':
            case 'whep':
            case 'web':
                $options = array('width' => 640, 'duration' => ($seconds > 0) ? $seconds + 2 : 60, 'muted' => false);
                if ($type == 'web') {
                    $options['height'] = 360;
                }
                // Постоянный id для адреса: повторный playMedia() того же потока продлевает
                // открытое окно (PiPup только перезапускает таймер), а не открывает новое
                $options['id'] = 'play_' . md5($url);
                $ok = $pipup->sendVideo($url, '', '▶', $type, $options);
                break;
            default: // audio
                $ok = $this->send($pipup, 'sendSound', array($url, '', '', ($seconds > 0) ? $seconds + 2 : 15));
        }

        if ($ok) {
            $this->success = TRUE;
            $this->message = 'OK (' . $type . ')';
        } else {
            $this->success = FALSE;
            $this->message = 'PiPup did not accept ' . $type . ' (see cms/debmes, channel terminals)';
        }
        return $this->success;
    }

    // Stop: закрыть все окна PiPup (видео и веб-страницы останавливаются, звук по ссылке доиграет)
    function stop()
    {
        $this->reset_properties();
        $this->success = (bool)$this->pipup()->cancelAll();
        $this->message = $this->success ? 'OK' : 'PiPup did not accept cancel';
        return $this->success;
    }

    // Картинка и звук — через очередь сообщений терминала (terminalsQueueCall), если его озвучка —
    // тоже pipup: не перебивают звучащее сообщение, показываются после него. Терминал не держат
    // (музыка не задерживает сообщения). Видео — сразу: окно продлевается по тому же id
    private function send($pipup, $method, $args)
    {
        if (is_array($this->terminal) && !empty($this->terminal['ID']) && isset($this->terminal['TTS_TYPE'])
            && $this->terminal['TTS_TYPE'] == 'pipup') {
            include_once(DIR_MODULES . 'terminals/terminals.class.php');
            if (function_exists('terminalsQueueCall')) {
                return terminalsQueueCall($this->terminal, $method, $args, array('busy' => 0));
            }
        }
        return call_user_func_array(array($pipup, $method), $args);
    }

    private function pipup()
    {
        include_once(DIR_MODULES . 'terminals/tts_addon.class.php');
        include_once(DIR_MODULES . 'terminals/tts/pipup.addon.php');
        return new pipup($this->terminal);
    }

    // Тип содержимого: audio | video | whep | image | svg | web
    private function mediaType($input)
    {
        if (preg_match('/^(rtsp|rtsps|rtmp):\/\//i', $input)) {
            return 'video';
        }
        if (stripos($input, '/api/webrtc') !== false) {
            return 'whep'; // go2rtc
        }
        $path = (string)parse_url($input, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, array('mp3', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'flac'), true)) {
            return 'audio';
        }
        if (in_array($ext, array('mp4', 'mkv', 'webm', 'mov', 'avi', 'm3u8', 'ts', 'mpd'), true)) {
            return 'video';
        }
        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'), true)) {
            return 'image';
        }
        if ($ext == 'svg') {
            return 'svg';
        }
        if (preg_match('/^https?:\/\//i', $input) && in_array($ext, array('', 'html', 'htm', 'php', 'cgi', 'mjpg', 'mjpeg'), true)) {
            return 'web'; // страница, MJPEG-поток и т.п.
        }
        return 'audio'; // как раньше: неизвестный локальный файл считаем звуком
    }
}
