<?php
/*
 * Сообщения на Kodi (тип плеера «kodi» в карточке терминала).
 *
 * Отличие от mediaplayer: play() дополнения app_player/kodi открывает файл через Player.Open,
 * текущий плейлист при этом не сохраняется. Поэтому сообщение добавляется в конец плейлиста
 * (pl_add) и включается по номеру (pl_play), а после сообщений mediaplayer возвращает прежний
 * трек по номеру и удаляет сообщения из плейлиста.
 * У Kodi номер элемента — номер в медиатеке; у файлов не из медиатеки его может не быть
 * (несколько элементов с номером 0). Номер используется, только если он однозначен в плейлисте,
 * иначе сообщение и возврат идут как в mediaplayer (запуск файла).
 *
 * Написано по коду app_player/kodi, на настоящем Kodi не проверялось.
 */

include_once(DIR_MODULES . 'terminals/tts/mediaplayer.addon.php');

class kodi_tts extends mediaplayer
{
    function __construct($terminal)
    {
        parent::__construct($terminal);
        $this->title = "Kodi";
        $this->description = 'Как mediaplayer, для Kodi: сообщение добавляется в плейлист; после сообщений возвращается прежний трек. Если номера треков в плейлисте неоднозначны, сообщение запускается как в mediaplayer.';
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
