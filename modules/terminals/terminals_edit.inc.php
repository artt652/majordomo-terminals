<?php

/*
* @version 0.3 (auto-set)
*/

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'terminals';

$rec = getTerminalByID($id);

$out['LOCATIONS'] = SQLSelect("SELECT TITLE FROM locations ORDER BY TITLE+0");
if ($location_id = $rec['LOCATION_ID']) {
    $location_title = SQLSelectOne("SELECT TITLE FROM locations WHERE ID = '". $location_id ."'");
    $out['LOCATION_TITLE'] = $location_title['TITLE'];
}

if ($this->mode == 'update') {
    $ok = 1;

    //updating 'NAME' (varchar, required)
    $rec['NAME'] = gr('name', 'trim');
    $rec['NAME'] = str_replace(' ', '', $rec['NAME']);
    if ($rec['NAME'] == '') {
        $out['ERR_NAME'] = 1;
        $ok = 0;
    }

    //updating 'TITLE' (varchar, required)
    $rec['TITLE'] = gr('title');
    if ($rec['TITLE'] == '') {
        $out['ERR_TITLE'] = 1;
        $ok = 0;
    }

    $rec['CANPLAY'] = gr('canplay', 'int');
    $rec['CANTTS'] = gr('cantts', 'int');

    $rec['MIN_MSG_LEVEL'] = gr('min_msg_level');

    //$rec['MAJORDROID_API'] = gr('majordroid_api', 'int');
	
    if ($location_id = SQLSelectOne("SELECT * FROM locations WHERE TITLE = '" . gr('location') . "'")) {
        $rec['LOCATION_ID'] = $location_id['ID'];
        $out['LOCATION_TITLE'] = gr('location');
    } else {
        $rec['LOCATION_ID'] = 0;
        $out['LOCATION_TITLE'] = gr('location');		
    }
    
    $rec['TTS_TYPE'] = gr('tts_type');
    $rec['PLAYER_TYPE'] = gr('player_type');
    // поля выбранных дополнений TTS и плеера (terminals_settings.inc.php): прежние столбцы
    // PLAYER_PORT и др. меняются, только если дополнение их показало
    terminalsSaveSettings($rec, 'tts', $rec['TTS_TYPE'], gr('tts_setting'), gr('tts_settings_json'));
    terminalsSaveSettings($rec, 'player', $rec['PLAYER_TYPE'], gr('player_setting'), gr('player_settings_json'));
    $rec['LINKED_OBJECT'] = gr('linked_object');
    /*
    global $level_linked_property;
    $rec['LEVEL_LINKED_PROPERTY'] = $level_linked_property;
    */

    $rec['HOST'] = gr('host');
    if (!$rec['HOST']) {
        $out['ERR_HOST'] = 1;
        $ok = 0;
    }

    //UPDATING RECORD
    if ($ok) {
        if ($rec['ID']) {
            SQLUpdate($table_name, $rec); // update
        } else {
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec); // adding new record
        }
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
    }
}

// отправка сообщения на этот терминал (форма «Отправить сообщение», terminals_send.inc.php)
if ($this->mode == 'send' && is_array($rec) && !empty($rec['ID'])) {
    $send_options = array(
        'from' => gr('send_from'),
        'image' => gr('send_image'),
        'sound' => gr('send_sound'),
        'voice' => gr('send_voice', 'int'),
    );
    if (gr('send_level') !== '') {
        $send_options['level'] = gr('send_level', 'int');
    }
    $out['SENT'] = terminalsSend($rec['NAME'], gr('send_message'), $send_options) ? 1 : 0;
    $out['SEND_FAILED'] = $out['SENT'] ? 0 : 1;
}
// ключ доступа к странице «Сообщения»: создать (показывается один раз), отозвать, «Требовать ключ»
if (is_array($rec) && !empty($rec['ID'])) {
    if ($this->mode == 'token_create') {
        $out['TOKEN_NEW'] = terminalsTokenCreate($rec['ID']);
    } elseif ($this->mode == 'token_revoke') {
        terminalsTokenRevoke($rec['ID']);
        $out['TOKEN_REVOKED'] = 1;
    } elseif ($this->mode == 'token_required') {
        terminalsTokenSetRequired($rec['ID'], gr('token_required', 'int'));
        $out['TOKEN_SAVED'] = 1;
    }
    $token_row = terminalsTokenRow($rec['ID']);
    $out['TOKEN_REQUIRED'] = !empty($token_row['REQUIRED']) ? 1 : 0;
    $out['TOKEN_EXISTS'] = !empty($token_row['TOKEN_HASH']) ? 1 : 0;
    $out['TOKEN_CREATED'] = !empty($token_row['CREATED']) ? date('d.m.Y H:i', (int)$token_row['CREATED']) : '';
    $out['TOKEN_LAST_USED'] = !empty($token_row['LAST_USED']) ? date('d.m.Y H:i', (int)$token_row['LAST_USED']) : '';
    $out['TOKEN_PAGE'] = ROOTHTML . 'popup/app_messages.html';
}

// открыть вкладку той формы, кнопку которой нажали. Через $this->tab: после admin() модуль
// записывает $out['TAB'] = $this->tab (terminals.class.php), прямое $out['TAB'] затиралось
if (strpos((string)$this->mode, 'token_') === 0) {
    $this->tab = 'tab_access';
} elseif ($this->mode == 'send') {
    $this->tab = 'tab_send';
}
$out['TAB'] = isset($this->tab) ? $this->tab : '';

$out['SEND_TERMINALS'] = SQLSelect("SELECT NAME, TITLE FROM terminals WHERE ID!=" . (int)(is_array($rec) ? $rec['ID'] : 0) . " ORDER BY TITLE");
$out['SEND_SOUNDS'] = array();
$sound_files = is_dir(ROOT . 'cms/sounds') ? scandir(ROOT . 'cms/sounds') : array();
foreach ((array)$sound_files as $sound_file) {
    if (preg_match('/\.(mp3|wav|ogg)$/i', $sound_file)) {
        $out['SEND_SOUNDS'][] = array('FILE' => 'cms/sounds/' . $sound_file, 'TITLE' => $sound_file);
    }
}

// сохранённые значения полей дополнений — для формы
$settings_values = array('tts' => array(), 'player' => array(), 'columns' => array());
foreach (array('tts' => 'TTS_SETTINGS', 'player' => 'PLAYER_SETTINGS') as $kind => $column) {
    $all = (is_array($rec) && isset($rec[$column])) ? json_decode((string)$rec[$column], true) : null;
    $settings_values[$kind] = is_array($all) ? $all : array();
}
foreach (terminalsSettingsColumns() as $column) {
    $settings_values['columns'][$column] = (is_array($rec) && isset($rec[$column])) ? (string)$rec[$column] : '';
}
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$out['SETTINGS_VALUES_JSON'] = json_encode($settings_values, $json_flags);

$rec_raw = is_array($rec) ? $rec : array(); // запись до экранирования — для полей дополнений
if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars($v);
        }
    }
}
outHash($rec, $out);

$out['TTS_ADDONS'] = array();
$tts_fields = array();
$player_fields = array('' => terminalsFieldsForForm(terminalsAddonFields(null, 'player')));
if (is_dir(DIR_MODULES . 'terminals/tts')) {
    include_once DIR_MODULES . 'terminals/tts_addon.class.php';
    $addons = scandir(DIR_MODULES . 'terminals/tts');
    if (is_array($addons)) {
        foreach ($addons as $addon_file) {
            $addon_file = DIR_MODULES . 'terminals/tts/' . $addon_file;
            if (is_file($addon_file)) {
                if (strtolower(substr($addon_file, -10)) == '.addon.php') {
                    $addon_name = basename($addon_file, '.addon.php');
                    $tts_description = '';
                    // поля дополнения TTS (settingsFields). Дополнение, у которого есть одноимённое
                    // дополнение плеера (majordroid), не подключаем: два класса с одним именем — Fatal
                    if (!file_exists(DIR_MODULES . 'app_player/addons/' . $addon_name . '.addon.php')) {
                        include_once($addon_file);
                        if (class_exists($addon_name)) {
                            $tts_addon = new $addon_name($rec_raw);
                            $tts_fields[$addon_name] = terminalsFieldsForForm(terminalsAddonFields($tts_addon, 'tts'));
                            // описание типа TTS (необязательное свойство $description), как у плееров
                            if (isset($tts_addon->description) && is_string($tts_addon->description)) {
                                $tts_description = $tts_addon->description;
                            }
                        }
                    }
                    $out['TTS_ADDONS'][] = array('NAME' => $addon_name, 'DESCRIPTION' => $tts_description);
                }
            }
        }
    }
}

if (is_dir(DIR_MODULES . 'app_player/addons')) {
    include_once(DIR_MODULES . 'app_player/addons.php');
    $addons = scandir(DIR_MODULES . 'app_player/addons');
    if (is_array($addons)) {
        foreach ($addons as $addon_file) {
            $addon_file = DIR_MODULES . 'app_player/addons/' . $addon_file;
            if (is_file($addon_file)) {
                if (strtolower(substr($addon_file, -10)) == '.addon.php') {
                    $addon_name = basename($addon_file, '.addon.php');
                    include_once($addon_file);
                    if (class_exists($addon_name)) {
                        if (is_subclass_of($addon_name, 'app_player_addon', TRUE)) {
                            if ($player = new $addon_name(NULL)) {
                                // Get player features
                                $features = array();
                                $reflection = new ReflectionClass($player);
                                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                                    if ($method->getDeclaringClass()->getName() == $reflection->getName()) {
                                        $method_name = $method->getName();
                                        if (substr($method_name, 0, 2) != '__' and !in_array($method_name, array('destroy', 'command', 'settingsFields'))) {
                                            $features[] = $method_name;
                                        }
                                    }
                                }
                                if (count($features)) {
                                    $player->description .= '<p><b>' . LANG_FEATURES_SUPPORTED . ':</b> ' . implode(', ', $features) . '.</p>';
                                } else {
                                    $player->description .= '<p style="color: #b94a48;"><b>' . LANG_NO_FEATURES_WARNING . '</b></p>';
                                }
                                $player_fields[$addon_name] = terminalsFieldsForForm(terminalsAddonFields($player, 'player'));
                                // Results
                                $out['PLAYER_ADDONS'][] = array(
                                    'TITLE' => $player->title,
                                    'VALUE' => $addon_name,
                                    'DESCRIPTION' => $player->description,
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}

$out['TTS_FIELDS_JSON'] = json_encode($tts_fields, $json_flags);
$out['PLAYER_FIELDS_JSON'] = json_encode($player_fields, $json_flags);

?>
