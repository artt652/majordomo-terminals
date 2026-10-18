<?php
/*
 * Сообщения на DLNA-устройство (тип плеера «dnla» в карточке терминала).
 *
 * Отличие от mediaplayer: многие DLNA-устройства не воспроизводят wav, а play() дополнения
 * app_player/dnla файл не перекодирует. Поэтому wav перед отправкой перекодируется в mp3 рядом
 * с исходным файлом — так же, как это делает say_message() в app_player/dnla.
 *
 * Написано по коду app_player/dnla, на настоящем DLNA-устройстве не проверялось.
 */

include_once(DIR_MODULES . 'terminals/tts/mediaplayer.addon.php');

class dnla_tts extends mediaplayer
{
    function __construct($terminal)
    {
        parent::__construct($terminal);
        $this->title = "DLNA";
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        if (strtolower(pathinfo($cached_file, PATHINFO_EXTENSION)) == 'wav') {
            $mp3 = preg_replace('/\.wav$/i', '.mp3', $cached_file);
            if (!file_exists($mp3)) {
                if (!defined('PATH_TO_FFMPEG')) {
                    if (IsWindowsOS()) {
                        define("PATH_TO_FFMPEG", SERVER_ROOT . '/apps/ffmpeg/ffmpeg.exe');
                    } else {
                        define("PATH_TO_FFMPEG", 'ffmpeg');
                    }
                }
                shell_exec(PATH_TO_FFMPEG . " -i " . escapeshellarg($cached_file) . " -acodec libmp3lame -ar 44100 " . escapeshellarg($mp3) . " 2>&1");
            }
            if (file_exists($mp3)) {
                $cached_file = $mp3;
            } else {
                DebMes('DLNA: could not convert to mp3: ' . $cached_file, 'terminals');
            }
        }
        return parent::sayCached($phrase, $level, $cached_file);
    }
}
