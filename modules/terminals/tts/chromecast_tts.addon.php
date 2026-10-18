<?php
/*
 * Сообщения на Chromecast (тип плеера «chromecast» в карточке терминала).
 *
 * Отличие от mediaplayer: в дополнении app_player/chromecast нет перемотки, поэтому
 * восстановление через play() + seekPlayerPosition() вернуло бы трек с начала.
 * Здесь прежний трек загружается сразу с нужной позиции: GChromecast::load($url, $currentTime)
 * из той же библиотеки (modules/app_player/libs/castv2), которой пользуется app_player/chromecast.
 *
 * Написано по коду app_player/chromecast, на настоящем Chromecast не проверялось.
 */

include_once(DIR_MODULES . 'terminals/tts/mediaplayer.addon.php');

class chromecast_tts extends mediaplayer
{
    function __construct($terminal)
    {
        parent::__construct($terminal);
        $this->title = "Chromecast";
        $this->description = 'Как mediaplayer, для Chromecast: после сообщений трек загружается сразу с прежней позиции.';
    }

    public function restoreState($state)
    {
        if (empty($state['file'])) {
            // музыка не играла — mediaplayer вернёт только громкость
            return parent::restoreState($state);
        }
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
}
