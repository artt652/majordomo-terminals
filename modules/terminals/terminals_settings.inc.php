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
