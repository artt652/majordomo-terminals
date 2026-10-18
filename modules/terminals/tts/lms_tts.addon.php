<?php
/*
 * Сообщения на Logitech Media Server / Squeezebox (тип плеера «lms» в карточке терминала).
 *
 * Отличие от mediaplayer: play() дополнения app_player/lms заменяет плейлист («playlist play»),
 * поэтому сообщение добавляется в конец плейлиста (pl_add) и включается по номеру (pl_play).
 * После сообщений mediaplayer возвращает прежний трек по номеру и удаляет сообщения из плейлиста.
 * Номер используется, только если он однозначен в плейлисте; иначе сообщение и возврат идут
 * как в mediaplayer (запуск файла).
 *
 * Написано по коду app_player/lms, на настоящем LMS не проверялось.
 */

include_once(DIR_MODULES . 'terminals/tts/mediaplayer.addon.php');

class lms_tts extends mediaplayer
{
    function __construct($terminal)
    {
        parent::__construct($terminal);
        $this->title = "Logitech Media Server";
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
}
