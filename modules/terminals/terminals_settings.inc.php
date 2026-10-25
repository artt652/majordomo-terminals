<?php
/*
 * Настройки дополнений терминала: отдельно для дополнения TTS и для дополнения плеера.
 *
 * Дополнение (modules/terminals/tts/*.addon.php или modules/app_player/addons/*.addon.php)
 * может объявить свои поля методом settingsFields(), который возвращает массив описаний:
 *   NAME    — имя поля. Все значения хранятся в JSON-полях terminals.TTS_SETTINGS /
 *             terminals.PLAYER_SETTINGS отдельно для каждого дополнения:
 *             {"pipup": {"PORT": "7979", "VOICE": "sanotts"}, "mpd": {"PLAYER_PORT": "6600"}}.
 *             Поля плеера с именами прежних столбцов (PLAYER_PORT, PLAYER_USERNAME, PLAYER_PASSWORD,
 *             PLAYER_CONTROL_ADDRESS) при сохранении ещё и копируются в эти столбцы — значениями
 *             выбранного плеера, — поэтому дополнения app_player читают их без изменений.
 *   TITLE   — подпись: текст или имя языковой константы (LANG_…);
 *   TYPE    — text | password | number | select | combo | checkbox (по умолчанию text);
 *   DEFAULT — значение, если ничего не сохранено;
 *   HINT    — подсказка внутри пустого поля;
 *   OPTIONS — значение => подпись (для select; для combo — подсказки, можно ввести своё).
 * Плеер без settingsFields() получает прежний набор полей: порт, имя пользователя, пароль.
 * TTS без settingsFields() полей не получает.
 * Значения своих полей дополнение читает через terminalsAddonSettings().
 */

// Переводы модуля: languages/terminals_<язык>.php и terminals_default.php (английский).
// Ядро подключает их само только при выводе модуля в шаблоне; здесь — для фона и дополнений
function terminalsLoadLanguage()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    if (defined('SETTINGS_SITE_LANGUAGE') && SETTINGS_SITE_LANGUAGE != '' && file_exists(ROOT . 'languages/terminals_' . SETTINGS_SITE_LANGUAGE . '.php')) {
        include_once(ROOT . 'languages/terminals_' . SETTINGS_SITE_LANGUAGE . '.php');
    }
    if (file_exists(ROOT . 'languages/terminals_default.php')) {
        include_once(ROOT . 'languages/terminals_default.php');
    }
}

// Перевод строки модуля по ключу (без LANG_)
function terminalsLang($key)
{
    terminalsLoadLanguage();
    return defined('LANG_' . $key) ? constant('LANG_' . $key) : $key;
}

// Поле «Сигнал перед сообщением»: звуки из cms/sounds (дополнения TTS с playFile())
function terminalsSignalField()
{
    $options = array('' => terminalsLang('TERMINALS_NONE'));
    foreach ((array)@scandir(ROOT . 'cms/sounds') as $file) {
        if (preg_match('/\.(mp3|wav|ogg)$/i', $file)) {
            $options['cms/sounds/' . $file] = $file;
        }
    }
    return array('NAME' => 'SIGNAL', 'TITLE' => terminalsLang('TERMINALS_SIGNAL'), 'TYPE' => 'select', 'OPTIONS' => $options);
}

// Поля, которые хранятся в своих столбцах таблицы terminals
function terminalsSettingsColumns()
{
    return array('PLAYER_PORT', 'PLAYER_USERNAME', 'PLAYER_PASSWORD', 'PLAYER_CONTROL_ADDRESS');
}

// Сохранённые значения JSON-полей дополнения: $kind — 'tts' или 'player'
function terminalsAddonSettings($terminal_rec, $kind, $addon)
{
    $column = ($kind == 'player') ? 'PLAYER_SETTINGS' : 'TTS_SETTINGS';
    $all = isset($terminal_rec[$column]) ? json_decode((string)$terminal_rec[$column], true) : null;
    if (is_array($all) && isset($all[$addon]) && is_array($all[$addon])) {
        return $all[$addon];
    }
    return array();
}

// Поля, которые объявило дополнение (или прежний набор для плеера)
function terminalsAddonFields($addon_obj, $kind)
{
    if (is_object($addon_obj) && method_exists($addon_obj, 'settingsFields')) {
        $fields = $addon_obj->settingsFields();
        return is_array($fields) ? $fields : array();
    }
    if ($kind == 'player') {
        return array(
            array('NAME' => 'PLAYER_PORT', 'TITLE' => 'LANG_PLAYER_PORT'),
            array('NAME' => 'PLAYER_USERNAME', 'TITLE' => 'LANG_PLAYER_USERNAME'),
            array('NAME' => 'PLAYER_PASSWORD', 'TITLE' => 'LANG_PLAYER_PASSWORD'),
        );
    }
    return array();
}

// Описания полей в виде для формы: подписи из языковых констант, все ключи на месте
function terminalsFieldsForForm($fields)
{
    $types = array('text', 'password', 'number', 'select', 'combo', 'checkbox');
    $result = array();
    foreach ($fields as $field) {
        if (!is_array($field) || empty($field['NAME'])) {
            continue;
        }
        $name = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $field['NAME']));
        $title = isset($field['TITLE']) ? (string)$field['TITLE'] : $name;
        if (preg_match('/^LANG_\w+$/', $title) && defined($title)) {
            $title = constant($title);
        }
        $type = (isset($field['TYPE']) && in_array($field['TYPE'], $types)) ? $field['TYPE'] : 'text';
        $options = array();
        if (isset($field['OPTIONS']) && is_array($field['OPTIONS'])) {
            foreach ($field['OPTIONS'] as $value => $label) {
                $options[] = array((string)$value, (string)$label);
            }
        }
        $result[] = array(
            'NAME' => $name,
            'TITLE' => $title,
            'TYPE' => $type,
            'DEFAULT' => isset($field['DEFAULT']) ? (string)$field['DEFAULT'] : '',
            'HINT' => isset($field['HINT']) ? (string)$field['HINT'] : '',
            'OPTIONS' => $options,
        );
    }
    return $result;
}

// Имена полей — латиница, цифры и _, значения — строки
function terminalsCleanValues($posted)
{
    $values = array();
    foreach ($posted as $name => $value) {
        $name = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $name));
        if ($name == '' || is_array($value)) {
            continue;
        }
        $values[$name] = trim((string)$value);
    }
    return $values;
}

// Записать значения из формы в запись терминала: всё — в JSON выбранного дополнения;
// для плеера прежние столбцы получают значения выбранного плеера (что он не показывает — пусто)
// $others_json — значения других дополнений этого вида, введённые в форме до смены типа (JSON)
function terminalsSaveSettings(&$rec, $kind, $addon, $posted, $others_json = '')
{
    if (!is_array($posted)) {
        return;
    }
    $column = ($kind == 'player') ? 'PLAYER_SETTINGS' : 'TTS_SETTINGS';
    $all = isset($rec[$column]) ? json_decode((string)$rec[$column], true) : null;
    if (!is_array($all)) {
        $all = array();
    }
    $key = ($addon != '') ? $addon : '_default';
    $others = is_string($others_json) ? json_decode($others_json, true) : null;
    if (is_array($others)) {
        foreach ($others as $other => $other_values) {
            $other = preg_replace('/[^A-Za-z0-9_]/', '', (string)$other);
            if ($other != '' && $other != $key && is_array($other_values)) {
                $all[$other] = terminalsCleanValues($other_values);
            }
        }
    }
    $values = terminalsCleanValues($posted);
    $all[$key] = $values;
    $rec[$column] = json_encode($all);
    if ($kind == 'player') {
        foreach (terminalsSettingsColumns() as $name) {
            $rec[$name] = isset($values[$name]) ? $values[$name] : '';
        }
    }
}

// --- Общие функции для других модулей и сценариев ---

// Терминал по имени, названию или адресу (или пустой массив)
function terminalsFind($name)
{
    $name = trim((string)$name);
    if ($name == '') {
        return array();
    }
    $list = getTerminalsByName($name, 1);
    if (!empty($list[0]['ID'])) {
        return $list[0];
    }
    $rec = SQLSelectOne("SELECT * FROM terminals WHERE TITLE='" . DBSafe($name) . "' LIMIT 1");
    if (!empty($rec['ID'])) {
        return $rec;
    }
    $list = getTerminalsByHost($name, 1);
    return !empty($list[0]['ID']) ? $list[0] : array();
}

// Минимальный уровень сообщений терминала: его поле или ThisComputer.minMsgLevel
function terminalsMinLevel($terminal_rec)
{
    $min_level = (int)getGlobal('ThisComputer.minMsgLevel');
    if (!empty($terminal_rec['MIN_MSG_LEVEL'])) {
        $min_level = (int)processTitle($terminal_rec['MIN_MSG_LEVEL']);
    }
    return max(0, $min_level);
}

// --- Голос терминала (поле TTS_ENGINE): какой генератор речи озвучивает его сообщения ---

// Кто сделал файл голоса: поле события SAY_CACHED_READY tts_engine (yandex_tts — «yandex_tts_v3») или tts (sanotts);
// '' — генератор о себе не сообщил
function terminalsVoiceEngine($details)
{
    foreach (array('tts_engine', 'tts') as $key) {
        if (is_array($details) && isset($details[$key]) && is_string($details[$key]) && trim($details[$key]) != '') {
            return strtolower(trim($details[$key]));
        }
    }
    return '';
}

// Играть ли терминалу файл этого генератора: голос не выбран или генератор не сообщил о себе — как раньше (играть);
// выбран — только файлы выбранного (имя модуля: «yandex_tts» подходит и к «yandex_tts_v3»)
function terminalsVoiceAccepts($terminal_rec, $engine)
{
    $selected = isset($terminal_rec['TTS_ENGINE']) ? strtolower(trim((string)$terminal_rec['TTS_ENGINE'])) : '';
    $engine = strtolower(trim((string)$engine));
    if ($selected == '' || $engine == '') {
        return true;
    }
    return $engine == $selected || strpos($engine, $selected . '_') === 0;
}

// Для генераторов речи: нужна ли фраза события от генератора $engine (перед синтезом).
//   if (function_exists('terminalsVoiceNeeded')) { if (!terminalsVoiceNeeded('sanotts', $event, $details)) return; }
//   elseif (!empty($details['ignoreVoice'])) return;
// Да — если среди терминалов-адресатов (SAY — все, кто говорит; SAYTO / ASK — destination) есть выбравший этот
// генератор, или терминал без выбранного голоса, а фразу ещё никто не озвучил (ignoreVoice). Нет терминалов-адресатов —
// как раньше: только если ignoreVoice не стоит
function terminalsVoiceNeeded($engine, $event, $details)
{
    $details = is_array($details) ? $details : array();
    $voiced = !empty($details['ignoreVoice']);
    if ($event == 'SAY') {
        $targets = SQLSelect("SELECT * FROM terminals WHERE CANTTS=1 AND TTS_TYPE!=''");
    } else {
        $target = isset($details['destination']) ? terminalsFind($details['destination']) : array();
        $targets = !empty($target['ID']) ? array($target) : array();
    }
    if (empty($targets)) {
        return !$voiced;
    }
    foreach ($targets as $terminal_rec) {
        $selected = isset($terminal_rec['TTS_ENGINE']) ? trim((string)$terminal_rec['TTS_ENGINE']) : '';
        if ($selected == '' ? !$voiced : terminalsVoiceAccepts($terminal_rec, $engine)) {
            return true;
        }
    }
    return false;
}

// Имя генератора для поля «Голос»: модуль, подписанный на SAY, если поле события начинается с его имени
// («yandex_tts_v3» → «yandex_tts»), иначе само поле
function terminalsVoiceEngineName($engine)
{
    $engine = strtolower(trim((string)$engine));
    $rec = SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='HOOK_EVENT_SAY'");
    $data = !empty($rec['VALUE']) ? json_decode($rec['VALUE'], true) : null;
    foreach (is_array($data) ? array_keys($data) : array() as $module) {
        $module = strtolower((string)$module);
        if ($module != '' && ($engine == $module || strpos($engine, $module . '_') === 0)) {
            return $module;
        }
    }
    return preg_replace('/[^\w-]/', '', $engine);
}

// Событие модуля для подписчиков (subscribeToEvent): TERMINAL_DELIVERED, TERMINAL_RENAMED, TERMINAL_DELETED.
// Сразу, в этом же запросе: подписчиков обычно нет — тогда ничего не происходит
function terminalsEvent($event, $details)
{
    if (function_exists('processSubscriptions')) {
        processSubscriptions($event, $details);
    }
}
