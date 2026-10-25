<?php
/*
	Language file for the terminals module: Ukrainian
*/

$dictionary = array(
	'TERMINALS_PICK_FROM_LIST'            => 'Вибрати зі списку',
	'TERMINALS_NONE'                      => 'немає',
	'TERMINALS_MESSAGE_VOLUME'            => 'Гучність повідомлень',
	'TERMINALS_MESSAGE_VOLUME_HINT'       => 'порожньо — не змінювати; 1–100 — на час повідомлень, потім попередня',
	'TERMINALS_SIGNAL'                    => 'Сигнал перед повідомленням',
	'TERMINALS_DESC_SIGNAL'               => '<b>Сигнал перед повідомленням</b> — звук із cms/sounds перед першим повідомленням серії (не раніше ніж через 20 с після попереднього). У MAIN не звучить, поки увімкнено загальний сигнал системи (інакше пролунає двічі).',
	'TERMINALS_DESC_MEDIAPLAYER'          => 'Голос повідомлень на плеєрі термінала (тип плеєра — у розділі «Може відтворювати медіа»). Файл голосу створює модуль синтезу мовлення. Повідомлення звучать по черзі, а те, що грало до них, повертається з того самого місця. Тип плеєра враховується: у MPD, Logitech Media Server і Kodi повідомлення додається до плейлиста (плейлист зберігається), у Chromecast трек повертається з тієї самої позиції, на DLNA йде mp3 замість wav (потрібен ffmpeg).',
	'TERMINALS_SHOW_VIDEO'                => 'Показувати відео з повідомлень (плеєри з екраном: VLC, Kodi, Chromecast, DLNA — лише mp4)',
	'TERMINALS_PLAYER_CAMERA_FORMAT'      => 'Камери',
	'TERMINALS_PLAYER_CAMERA_FORMAT_HINT' => 'Як плеєр отримує камери go2rtc. RTSP не відтворюють VLC без модуля live555 (збірки Debian і Raspberry Pi OS) і Chromecast. HTTP (MP4) — з API go2rtc по TCP; пристрою потрібен доступ до API без пароля.',
);

foreach ($dictionary as $key => $value) {
	if (!defined('LANG_' . $key)) {
		define('LANG_' . $key, $value);
	}
}
