<?php
/*
 * Сообщения на плеер MPD (тип плеера «mpd» в карточке терминала).
 *
 * Отличия от mediaplayer:
 * - play() дополнения app_player/mpd очищает плейлист, поэтому сообщение не запускается через play(),
 *   а добавляется в конец плейлиста (pl_add) и включается по номеру (pl_play). Плейлист сохраняется,
 *   после сообщений mediaplayer возвращает прежний трек по номеру и удаляет сообщения из плейлиста;
 * - статус app_player/mpd отдаёт в track_id позицию трека в плейлисте, а не его номер (Id),
 *   поэтому saveState() берёт трек из плейлиста по позиции.
 * Если добавить в плейлист не удалось, сообщение играет как в mediaplayer.
 */

include_once(DIR_MODULES . 'terminals/tts/mediaplayer.addon.php');

class mpd_tts extends mediaplayer
{
    function __construct($terminal)
    {
        parent::__construct($terminal);
        $this->title = "MPD";
        $this->description = 'Как mediaplayer, для плеера MPD: сообщение добавляется в плейлист, а не заменяет его; после сообщений возвращается прежний трек с той же позиции.';
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        $url = $this->messageUrl($cached_file);
        if ($url == '') {
            return false;
        }
        if ($this->playInPlaylist($url)) {
            return true;
        }
        return parent::sayCached($phrase, $level, $cached_file);
    }

    // статус app_player/mpd: track_id — позиция трека, элементы плейлиста идут по порядку
    protected function currentFile($played)
    {
        $position = isset($played['track_id']) ? (int)$played['track_id'] : -1;
        return ($position >= 0 && isset($played[$position]['file'])) ? $played[$position]['file'] : '';
    }

    public function saveState()
    {
        $this->playerSession();
        $played = getPlayerStatus($this->terminal['NAME']);
        if (!is_array($played) || !isset($played['state'], $played['track_id']) || $played['state'] != 'playing') {
            return false;
        }
        // элементы плейлиста идут в ответе по порядку: позиция трека — индекс в нём
        $position = (int)$played['track_id'];
        if (!isset($played[$position]['id'], $played[$position]['file'])) {
            return false;
        }
        $file = $played[$position]['file'];
        if ($file == '' || preg_match('/cms[\\\\\/]cached[\\\\\/]voice/i', $file)) {
            return false;
        }
        $state = array(
            'file' => $file,
            'time' => isset($played['time']) ? (int)$played['time'] : 0,
            'track_id' => (int)$played[$position]['id'],
        );
        if (isset($played['volume']) && (int)$played['volume'] > 0) {
            $state['volume'] = (int)$played['volume'];
        }
        return $state;
    }
}
