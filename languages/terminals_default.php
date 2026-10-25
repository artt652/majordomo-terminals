<?php
/*
	Language file for the terminals module: Default (English)
*/

$dictionary = array(
	'TERMINALS_PICK_FROM_LIST'            => 'Choose from the list',
	'TERMINALS_NONE'                      => 'none',
	'TERMINALS_MESSAGE_VOLUME'            => 'Message volume',
	'TERMINALS_MESSAGE_VOLUME_HINT'       => 'empty — do not change; 1–100 — during messages, then the previous one',
	'TERMINALS_SIGNAL'                    => 'Signal before a message',
	'TERMINALS_DESC_SIGNAL'               => '<b>Signal before a message</b> — a sound from cms/sounds before the first message of a series (at least 20 s after the previous one). On MAIN it does not play while the system signal is on (otherwise it would sound twice).',
	'TERMINALS_DESC_MEDIAPLAYER'          => 'Message voice on the terminal player (player type is in the “Can play media” section). The voice file is made by a speech generator module. Messages play one after another, and what was playing before them comes back from the same place. The player type is taken into account: MPD, Logitech Media Server and Kodi get the message added to the playlist (the playlist is kept), Chromecast resumes the track from the same position, DLNA gets mp3 instead of wav (ffmpeg is required).',
	'TERMINALS_SHOW_VIDEO'                => 'Show video from messages (players with a screen: VLC, Kodi, Chromecast, DLNA — mp4 only)',
	'TERMINALS_PLAYER_CAMERA_FORMAT'      => 'Cameras',
	'TERMINALS_PLAYER_CAMERA_FORMAT_HINT' => 'How the player gets go2rtc cameras. RTSP is not played by VLC without the live555 module (Debian and Raspberry Pi OS builds) and by Chromecast. HTTP (MP4) — from the go2rtc API over TCP; the device needs access to the API without a password.',
	'TERMINALS_TTS_ENGINE'                => 'Voice',
	'TERMINALS_TTS_ENGINE_ANY'            => 'any',
	'TERMINALS_TTS_ENGINE_HINT'           => 'Speech synthesis module whose files this terminal plays. Empty — any (as before). Files from a module that does not report itself are always played.',
);

foreach ($dictionary as $key => $value) {
	if (!defined('LANG_' . $key)) {
		define('LANG_' . $key, $value);
	}
}
