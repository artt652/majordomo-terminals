# majordomo-terminals — ветка `alpha`

Минимальные доработки модуля «Терминалы» MajorDoMo поверх [sergejey/majordomo](https://github.com/sergejey/majordomo), ветка `alpha`. В репозитории лежат только изменённые и новые файлы, в той же структуре каталогов.

Эта ветка — то, что нужно другим модулям от «Терминалов», и ничего сверх этого: очередь сообщений, настройки дополнений, общие функции и события. Мессенджер «Сообщения», камеры go2rtc и PiPup — отдельные модули (переносятся в свои репозитории). Полная версия со всем этим вместе — ветка `main`, метка `monolith-2026-10-24`.

## Файлы

| Файл | Что это |
|---|---|
| `modules/terminals/terminals.class.php` | Изменён: очередь для дополнений с `useQueue()`, события `MINUTELY` и `startup`, таблицы `terminals_queue` и `terminals_state`, поля `TTS_SETTINGS` и `PLAYER_SETTINGS`, `terminalSay()` с картинкой, отправителем и номером записи, события `TERMINAL_DELIVERED` / `TERMINAL_RENAMED` / `TERMINAL_DELETED` |
| `modules/terminals/terminals_queue.inc.php` | Новый: очередь, сохранение и восстановление плеера, сторож, пропуск выключенных устройств, журнал с адресами |
| `modules/terminals/terminals_media.inc.php` | Новый: длительность звука и видео из заголовка файла (MP4/MOV/M4A, WAV, MP3, OGG), иначе ffprobe/ffmpeg |
| `modules/terminals/terminals_settings.inc.php` | Новый: настройки дополнений TTS и плеера (поля объявляет дополнение), переводы модуля, общие функции |
| `modules/terminals/terminals_edit.inc.php` | Изменён: поля дополнений в форме терминала и их сохранение; при переименовании — событие |
| `templates/terminals/terminals_edit.html` | Изменён: поля плеера и TTS строятся по описанию из дополнения |
| `modules/terminals/tts/mediaplayer.addon.php` | Изменён: очередь вместо заданий `allsay-target-*`, сохранение и восстановление плеера, особенности MPD / LMS / Kodi / Chromecast / DLNA, видео и потоки на плеерах с экраном; исправлен запуск на PHP 8 |
| `languages/terminals_default.php`, `terminals_ru.php`, `terminals_ua.php` | Новые: переводы полей (английский, русский, украинский) |

`modules/app_player`, `lib/` и остальное ядро не меняются.

## Очередь сообщений

- **Только для дополнений, которые её просят** (`useQueue()` возвращает true; сейчас — `mediaplayer`). Остальные работают как раньше.
- **Сообщения не перебивают друг друга.** На одном терминале — по очереди, разные терминалы — параллельно. Очередь разбирает отложенное задание (`setTimeOut`), отдельного цикла нет.
- **Срочные идут первыми** (`ask` и уровень ≥ 9): прерывают обычное сообщение и идут раньше всех обычных; если звучит другое срочное — ждут его конца.
- **Повторы склеиваются.** Та же фраза второй раз не ставится, готовый файл голоса присоединяется к своей записи.
- **Плеер восстанавливается.** Перед первым сообщением серии сохраняется, что играло (`saveState()`), после последнего — включается с того же места (`restoreState()`).
- **Конец сообщения** — по длине файла; если дополнение умеет `isPlaying()`, ожидание продлевается, пока плеер ещё играет сообщение.
- **Громкость сообщений и сигнал перед сообщением** — поля `mediaplayer`.
- **Картинка и отправитель.** Картинка из `say('… image:cms/путь.jpg', уровень)` и поля `image` / `from` события `SAYTO` идут с сообщением; их показывает дополнение с `showMessage()`.
- **Текст без озвучки** (`terminalSay(…, $silent = true)`) голос не задерживает.
- **Выключенные устройства не ждём**: перед обращением — проверка порта устройства (не дольше 1 с, адрес даёт `deviceAddress()` дополнения); не ответило — 60 с к нему не обращаемся.
- **Старые сообщения** (старше 10 минут) не проигрываются; сторож (`MINUTELY`) перезапускает застрявшую очередь, `startup` сбрасывает «занято».
- **Журнал** (`cms/debmes/<дата>/terminals.log`) — с адресами устройств; пароли, ключи и подписи в ссылках скрываются.

## Для других модулей и сценариев

Версия возможностей — константа `TERMINALS_API` (сейчас 1). Модуль, которому нужна очередь или события, проверяет её (`defined('TERMINALS_API')`) и со старыми «Терминалами» работает в урезанном виде.

**Отправка на терминал**
- `terminalSay($terminal_rec, $message, $level, $image = '', $sender = '', $ref = 0, $silent = false)` — метод класса `terminals`.
- Событие `SAYTO` принимает необязательные поля `image`, `from` и `ref` (номер записи вызывающего модуля).
- `terminalsQueueCall($terminal, $method, $args, $options)` — метод дополнения через очередь (для сценариев). Общие методы: `sendImage`, `sendSound`, `sendWebImage`, `sendVideo`; дополнение может объявить свои методом `queueMethods()`. `$options`: `level`, `busy` (сколько секунд терминал занят), `refs` (номера записей).

  ```php
  include_once(DIR_MODULES . 'terminals/terminals.class.php');
  terminalsQueueCall('Кухня', 'sendImage', array('cms/cached/snapshot.jpg', 'Звонок в дверь'));
  ```
- `terminalsFind($name)` — терминал по имени, названию или адресу; `terminalsMinLevel($terminal_rec)` — его минимальный уровень сообщений.

**События** (подписка — `subscribeToEvent('мой_модуль', 'TERMINAL_DELIVERED')`):

| Событие | Когда | Поля |
|---|---|---|
| `TERMINAL_DELIVERED` | сообщение с номером записи отдано устройству | `terminal`, `terminal_id`, `refs` (через запятую) |
| `TERMINAL_RENAMED` | терминал переименован в карточке | `old`, `new`, `terminal_id` |
| `TERMINAL_DELETED` | терминал удалён | `terminal`, `terminal_id` |

**Необязательные методы дополнения TTS** (проверяются через `method_exists`, базовый `tts_addon.class.php` не меняется): `useQueue`, `saveState` / `restoreState`, `isPlaying`, `showMessage`, `playFile`, `sendVideo`, `canShowVideo`, `deviceAddress`, `queueMethods`, `settingsFields`.

## Настройки дополнений

Дополнение TTS (`modules/terminals/tts/`) и дополнение плеера (`modules/app_player/addons/`) объявляют поля карточки терминала методом `settingsFields()`:

```php
function settingsFields()
{
    return array(
        array('NAME' => 'PORT', 'TITLE' => 'Порт', 'HINT' => 'пусто — 7979'),
        array('NAME' => 'VOICE', 'TITLE' => 'Голос', 'TYPE' => 'combo', 'OPTIONS' => array('' => 'Любой', 'device' => 'Устройство')),
    );
}
```

- `TYPE`: `text` (по умолчанию), `password`, `number`, `select`, `combo` (подсказки, можно ввести своё), `checkbox`; ещё `DEFAULT`, `HINT`, `OPTIONS`.
- Значения хранятся в `TTS_SETTINGS` / `PLAYER_SETTINGS` отдельно для каждого дополнения: смена типа их не стирает. Поля плеера с именами прежних столбцов (`PLAYER_PORT`, `PLAYER_USERNAME`, `PLAYER_PASSWORD`, `PLAYER_CONTROL_ADDRESS`) копируются и в столбцы — дополнения `app_player` читают их как раньше.
- Дополнение читает свои значения через `terminalsAddonSettings($this->terminal, 'tts' или 'player', 'имя_дополнения')`.
- Плеер без `settingsFields()` получает прежний набор: порт, имя пользователя, пароль. TTS без него полей не получает. Описание типа TTS — свойство `$description`.

## Тип TTS `mediaplayer`

Особенности плеера `mediaplayer` учитывает сам, по типу плеера терминала:

| Плеер терминала | Что делает mediaplayer |
|---|---|
| VLC (HTTP) и другие | сообщение — запуском файла; трек остался в плейлисте под тем же номером — возврат через `pl_play` |
| MPD (проверено на MPD 0.23) | сообщение добавляется в конец плейлиста — плейлист сохраняется; позиция трека в статусе MPD учтена |
| Logitech Media Server, Kodi | как MPD (не проверено на устройствах) |
| Chromecast | прежний трек загружается сразу с позиции (не проверено) |
| DLNA | wav перекодируется в mp3, нужен ffmpeg (не проверено) |

Видео и потоки (`sendVideo()`, через очередь) — у плееров с экраном (VLC, Kodi, Chromecast, DLNA — только mp4), с галочкой «Показывать видео». Поле «Камеры»: RTSP или HTTP MP4 (для VLC без live555, Chromecast, DLNA).

## Установка

Скопировать файлы поверх MajorDoMo (ветка `alpha`) и переустановить модуль «Терминалы»: создадутся таблицы `terminals_queue`, `terminals_state`, поля `TTS_SETTINGS`, `PLAYER_SETTINGS` и подписки на `MINUTELY` и `startup`. Существующие таблицы и поля не меняются.

Совместимо с PHP 7 и 8.
