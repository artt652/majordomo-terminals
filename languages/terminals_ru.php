<?php
/*
	Language file for the terminals module: Russian
*/

$dictionary = array(
	'TERMINALS_PICK_FROM_LIST'            => 'Выбрать из списка',
	'TERMINALS_NONE'                      => 'нет',
	'TERMINALS_MESSAGE_VOLUME'            => 'Громкость сообщений',
	'TERMINALS_MESSAGE_VOLUME_HINT'       => 'пусто — не менять; 1–100 — на время сообщений, потом прежняя',
	'TERMINALS_SIGNAL'                    => 'Сигнал перед сообщением',
	'TERMINALS_DESC_SIGNAL'               => '<b>Сигнал перед сообщением</b> — звук из cms/sounds перед первым сообщением серии (не раньше чем через 20 с после прошлого). У MAIN не звучит, пока включён общий сигнал системы (иначе прозвучит дважды).',
	'TERMINALS_DESC_MEDIAPLAYER'          => 'Голос сообщений на плеере терминала (тип плеера — в разделе «Может воспроизводить медиа»). Файл голоса делает модуль синтеза речи. Сообщения звучат по очереди, а то, что играло до них, возвращается с того же места. Тип плеера учитывается: у MPD, Logitech Media Server и Kodi сообщение добавляется в плейлист (плейлист сохраняется), у Chromecast трек возвращается с той же позиции, на DLNA уходит mp3 вместо wav (нужен ffmpeg).',
	'TERMINALS_SHOW_VIDEO'                => 'Показывать видео из сообщений (плееры с экраном: VLC, Kodi, Chromecast, DLNA — только mp4)',
	'TERMINALS_PLAYER_CAMERA_FORMAT'      => 'Камеры',
	'TERMINALS_PLAYER_CAMERA_FORMAT_HINT' => 'Как плеер получает камеры go2rtc. RTSP не играют VLC без модуля live555 (сборки Debian и Raspberry Pi OS) и Chromecast. HTTP (MP4) — с API go2rtc по TCP; устройству нужен доступ к API без пароля.',
	'TERMINALS_TTS_ENGINE'                => 'Голос',
	'TERMINALS_TTS_ENGINE_ANY'            => 'любой',
	'TERMINALS_TTS_ENGINE_HINT'           => 'Модуль синтеза речи, файлы которого играет этот терминал. Пусто — любой (как раньше). Файлы модуля, который не сообщает о себе, играют всегда.',
);

foreach ($dictionary as $key => $value) {
	if (!defined('LANG_' . $key)) {
		define('LANG_' . $key, $value);
	}
}
