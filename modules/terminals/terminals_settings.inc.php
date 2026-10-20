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

// Статус терминала для страницы «Сообщения»: online — активность не старше 3 минут
// (страница «Сообщения», shoutbox, command.php, ping каждый час); reachable — устройство на связи
// по данным ядра (IS_ONLINE); last — время последней активности для показа
function terminalsStatus($terminal_rec)
{
    $last = !empty($terminal_rec['LATEST_ACTIVITY']) ? strtotime($terminal_rec['LATEST_ACTIVITY']) : 0;
    if ($last <= 0) {
        $last = 0;
    }
    return array(
        'online' => ($last && time() - $last <= 180) ? 1 : 0,
        'reachable' => !empty($terminal_rec['IS_ONLINE']) ? 1 : 0,
        'last' => $last ? (date('Y-m-d', $last) == date('Y-m-d') ? date('H:i', $last) : date('d.m H:i', $last)) : '',
    );
}

// Значения полей дополнения для показа: подписи из описания полей, пароли скрыты, пустое — «по умолчанию»
function terminalsSettingsForView($fields, $values)
{
    $result = array();
    foreach (terminalsFieldsForForm($fields) as $field) {
        $value = isset($values[$field['NAME']]) ? (string)$values[$field['NAME']] : '';
        if ($field['TYPE'] == 'password') {
            $value = ($value != '') ? '••••••' : '';
        } elseif ($field['TYPE'] == 'checkbox') {
            $value = ($value == '1') ? terminalsLang('TERMINALS_YES') : terminalsLang('TERMINALS_NO');
        } elseif (($field['TYPE'] == 'select' || $field['TYPE'] == 'combo') && $value != '') {
            foreach ($field['OPTIONS'] as $option) {
                if ($option[0] === $value) {
                    $value = $option[1];
                }
            }
        }
        if ($value == '') {
            $value = ($field['DEFAULT'] != '') ? $field['DEFAULT'] : terminalsLang('TERMINALS_BY_DEFAULT');
        }
        $result[] = array('title' => strip_tags($field['TITLE']), 'value' => $value);
    }
    return $result;
}

// Состояние экрана словами (pipup: screenState()): «включён», «заставка», «выключен», «нет связи»
function terminalsScreenText($screen)
{
    if (!is_array($screen)) {
        return terminalsLang('TERMINALS_SCREEN_UNKNOWN');
    }
    if ($screen['visible']) {
        return terminalsLang('TERMINALS_SCREEN_ON');
    }
    return terminalsLang($screen['dreaming'] ? 'TERMINALS_SCREEN_DREAM' : 'TERMINALS_SCREEN_OFF');
}

// Возможности и текущие настройки терминала (только для просмотра) — карточка на странице «Сообщения»
function terminalsInfo($terminal_rec)
{
    $info = array(
        'name' => $terminal_rec['NAME'],
        'title' => $terminal_rec['TITLE'],
        'host' => (string)$terminal_rec['HOST'],
        'room' => '',
        'min_level' => (string)$terminal_rec['MIN_MSG_LEVEL'],
        'status' => terminalsStatus($terminal_rec),
        'tts' => null,
        'player' => null,
    );
    if (!empty($terminal_rec['LOCATION_ID'])) {
        $room = SQLSelectOne("SELECT TITLE FROM locations WHERE ID=" . (int)$terminal_rec['LOCATION_ID']);
        $info['room'] = isset($room['TITLE']) ? $room['TITLE'] : '';
    }
    // озвучка (TTS): что умеет дополнение — по его необязательным методам
    if ($terminal_rec['CANTTS'] && $terminal_rec['TTS_TYPE'] != '') {
        $type = preg_replace('/[^\w]/', '', $terminal_rec['TTS_TYPE']);
        $tts = array('type' => $type, 'title' => $type, 'capabilities' => array(), 'settings' => array());
        $file = DIR_MODULES . 'terminals/tts/' . $type . '.addon.php';
        // одноимённое дополнение плеера (majordroid) — тот же класс, не подключаем
        if (is_file($file) && !file_exists(DIR_MODULES . 'app_player/addons/' . $type . '.addon.php')) {
            include_once DIR_MODULES . 'terminals/tts_addon.class.php';
            include_once $file;
            if (class_exists($type)) {
                $addon = new $type($terminal_rec);
                if (isset($addon->title) && is_string($addon->title) && $addon->title != '') {
                    $tts['title'] = $addon->title;
                }
                $caps = array('say' => 'TERMINALS_CAP_SAY', 'showMessage' => 'TERMINALS_CAP_IMAGE', 'playFile' => 'TERMINALS_CAP_SOUND',
                    'sendVideo' => 'TERMINALS_CAP_VIDEO', 'ask' => 'TERMINALS_CAP_ASK', 'useQueue' => 'TERMINALS_CAP_QUEUE',
                    'restoreState' => 'TERMINALS_CAP_RESTORE', 'screenState' => 'TERMINALS_CAP_SCREEN');
                foreach ($caps as $method => $key) {
                    // pipup: saveState/restoreState — экран после серии, а не плеер
                    if ($method == 'restoreState' && method_exists($addon, 'screenState')) {
                        continue;
                    }
                    if (method_exists($addon, $method) && ($method != 'useQueue' || $addon->useQueue())) {
                        $tts['capabilities'][] = terminalsLang($key);
                    }
                }
                // экран устройства (pipup): включён, заставка, выключен
                if (method_exists($addon, 'screenState')) {
                    $info['screen'] = terminalsScreenText($addon->screenState());
                }
                $tts['settings'] = terminalsSettingsForView(terminalsAddonFields($addon, 'tts'), terminalsAddonSettings($terminal_rec, 'tts', $type));
            }
        }
        $info['tts'] = $tts;
    }
    // плеер: возможности — публичные методы дополнения app_player (как в карточке терминала)
    if ($terminal_rec['CANPLAY'] && $terminal_rec['PLAYER_TYPE'] != '') {
        $type = preg_replace('/[^\w]/', '', $terminal_rec['PLAYER_TYPE']);
        $player = array('type' => $type, 'title' => $type, 'capabilities' => array(), 'settings' => array());
        $file = DIR_MODULES . 'app_player/addons/' . $type . '.addon.php';
        if (is_file($file) && is_file(DIR_MODULES . 'app_player/addons.php')) {
            include_once DIR_MODULES . 'app_player/addons.php';
            include_once $file;
            if (class_exists($type) && is_subclass_of($type, 'app_player_addon', true)) {
                $addon = new $type(null);
                if (isset($addon->title) && $addon->title != '') {
                    $player['title'] = strip_tags($addon->title);
                }
                $reflection = new ReflectionClass($addon);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $name = $method->getName();
                    if ($method->getDeclaringClass()->getName() == $reflection->getName() && substr($name, 0, 2) != '__'
                        && !in_array($name, array('destroy', 'command', 'settingsFields'))) {
                        $player['capabilities'][] = $name;
                    }
                }
                $values = terminalsAddonSettings($terminal_rec, 'player', $type);
                if (!count($values)) {
                    // настройки ещё не в JSON — прежние столбцы
                    foreach (terminalsSettingsColumns() as $column) {
                        $values[$column] = isset($terminal_rec[$column]) ? $terminal_rec[$column] : '';
                    }
                }
                $player['settings'] = terminalsSettingsForView(terminalsAddonFields($addon, 'player'), $values);
            }
        }
        $info['player'] = $player;
    }
    return $info;
}

// Адрес вебсокета для внешнего доступа к странице «Сообщения»: ws://… или wss://… (полный адрес)
// либо путь от адреса страницы (/majordomo — через тот же хост и порт, например за обратным прокси); иначе ''
function terminalsWsUrl($value)
{
    $value = trim((string)$value);
    if (preg_match('~^wss?://[^\s"\'<>]+$~i', $value) || preg_match('~^/[^\s"\'<>]*$~', $value)) {
        return $value;
    }
    return '';
}
