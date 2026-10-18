<?php

class mediaplayer extends tts_addon
{
    // Свои свойства: в базовом tts_addon $title объявлен private, запись из наследника
    // создавала бы динамическое свойство (Deprecated в PHP 8.2+). Описание показывается в карточке терминала
    public $title = 'MediaPlayer';
    public $description = '';

    function __construct($terminal)
    {
        $this->title = "MediaPlayer";
        $this->description = 'Голос сообщений на плеере терминала (тип плеера — в разделе «Может воспроизводить медиа»). '
            . 'Файл голоса делает модуль синтеза речи. Сообщения звучат по очереди, а то, что играло до них, '
            . 'возвращается с того же места. Для MPD, Logitech Media Server, Kodi, Chromecast и DLNA есть свои типы: '
            . '<b>mpd_tts</b>, <b>lms_tts</b>, <b>kodi_tts</b>, <b>chromecast_tts</b>, <b>dnla_tts</b>.';
        parent::__construct($terminal);
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        $cached_file_url = $this->messageUrl($cached_file);
        if ($cached_file_url == '') {
            return false;
        }
        // очередь, порядок и восстановление плеера ведёт модуль terminals (terminals_queue.inc.php)
        playMedia($cached_file_url, $this->terminal['TITLE']);
        return true;
    }

    // Ссылка, по которой плеер заберёт файл сообщения (пусто, если путь не из cms/cached)
    protected function messageUrl($cached_file)
    {
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
        //DebMes("Unknown file path format: " . $cached_filename, 'terminals');
        return '';
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
        if (!is_array($played) || !isset($played['state']) || $played['state'] != 'playing') {
            return false;
        }
        $file = $this->currentFile($played);
        if ($file == '' || preg_match('/cms[\\\\\/]cached[\\\\\/]voice/i', $file)) {
            return false;
        }
        $state = array('file' => $file, 'time' => isset($played['time']) ? (int)$played['time'] : 0);
        if (isset($played['track_id']) && (int)$played['track_id'] >= 0) {
            $state['track_id'] = (int)$played['track_id'];
        }
        if (isset($played['volume']) && (int)$played['volume'] > 0) {
            $state['volume'] = (int)$played['volume'];
        }
        return $state;
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
        return (bool)preg_match('/cms[\\\\\/]cached[\\\\\/]voice/i', $file);
    }

    // Текущий файл плеера из ответа getPlayerStatus()
    protected function currentFile($played)
    {
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
            return false;
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
    // Для плееров, у которых play() заменяет плейлист (mpd_tts, lms_tts, kodi_tts). Возвращает успех
    protected function playInPlaylist($url)
    {
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

    // Убрать из плейлиста файлы сообщений (cms/cached/voice); остальные треки не трогаются
    protected function playlistCleanup()
    {
        foreach ($this->playlist() as $item) {
            if (is_array($item) && isset($item['id'], $item['file']) && preg_match('/cms[\\\\\/]cached[\\\\\/]voice/i', $item['file'])) {
                $this->playerCommand('pl_delete', (int)$item['id']);
            }
        }
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
