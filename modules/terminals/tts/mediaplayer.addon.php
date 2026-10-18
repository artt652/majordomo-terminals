<?php

class mediaplayer extends tts_addon
{
    function __construct($terminal)
    {
        $this->title = "MediaPlayer";
        parent::__construct($terminal);
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        // poluchaem adress cashed files dlya zapuska ego na vosproizvedeniye
        if (preg_match('/\/cms\/cached.+/', $cached_file, $m)) {
            $server_ip = getLocalIp();
            if (!$server_ip) {
                //DebMes("Server IP not found", 'terminals');
                return false;
            } else {
                $cached_file_url = 'http://' . $server_ip . $m[0];
            }
            // как в dnla.addon.php: если по адресу без порта файл недоступен, берём адрес из BASE_URL
            if (!remote_file_exists($cached_file_url)) {
                $cached_file_url = BASE_URL . $m[0];
            }
        } else {
            //DebMes("Unknown file path format: " . $cached_filename, 'terminals');
            return false;
        }
        // очередь, порядок и восстановление плеера ведёт модуль terminals (terminals_queue.inc.php)
        playMedia($cached_file_url, $this->terminal['TITLE']);
        return true;
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
        $file = '';
        if (!empty($played['file'])) {
            $file = $played['file'];
        } elseif (isset($played['track_id'])) {
            // часть плееров (vlcweb) отдаёт файл только в плейлисте: ищем текущий трек по track_id
            foreach ($played as $key => $item) {
                if (is_int($key) && is_array($item) && isset($item['id'], $item['file']) && $item['id'] == $played['track_id']) {
                    $file = $item['file'];
                    break;
                }
            }
        }
        if ($file == '' || preg_match('/cms[\\\\\/]cached[\\\\\/]voice/i', $file)) {
            return false;
        }
        $state = array('file' => $file, 'time' => isset($played['time']) ? (int)$played['time'] : 0);
        if (isset($played['volume']) && (int)$played['volume'] > 0) {
            $state['volume'] = (int)$played['volume'];
        }
        return $state;
    }

    // Вернуть файл, позицию и громкость после сообщений
    public function restoreState($state)
    {
        if (empty($state['file'])) {
            return false;
        }
        $title = $this->terminal['TITLE'];
        $this->playerSession();
        // запуск напрямую, а не через playMedia(): тот отправляет команду в фоне,
        // и перемотка могла бы прийти в плеер раньше запуска
        include_once(DIR_MODULES . 'app_player/app_player.class.php');
        $player = new app_player();
        $player->play_terminal = $this->terminal['NAME'];
        $player->command = 'play';
        $player->param = $state['file'];
        $player->ajax = TRUE;
        $player->intCall = TRUE;
        $player->usual($out);
        if (!empty($state['time'])) {
            seekPlayerPosition($title, (int)$state['time']);
            // плеер мог ещё не загрузить трек: проверяем и при необходимости повторяем перемотку через 2 с
            $now = getPlayerStatus($this->terminal['NAME']);
            if (!is_array($now) || !isset($now['time']) || abs((int)$now['time'] - (int)$state['time']) > 5) {
                setTimeOut('terminals_restore_seek_' . (int)$this->terminal['ID'],
                    'seekPlayerPosition(' . var_export($title, true) . ', ' . (int)$state['time'] . ');', 2);
            }
        }
        if (!empty($state['volume'])) {
            setPlayerVolume($title, (int)$state['volume']);
        }
        return true;
    }

    // app_player::usual() пишет в $session->data. В заданиях и фоновых событиях (objects/?job=…)
    // сессии нет: PHP 7 сам создавал пустой объект, PHP 8 падает с ошибкой. Делаем то же, что PHP 7.
    private function playerSession()
    {
        global $session;
        if (!is_object($session)) {
            $session = new stdClass();
            $session->data = array();
        }
    }
}
