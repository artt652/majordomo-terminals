<?php
/**
 * Terminals
 *
 * Terminals
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <sergejey@gmail.com> https://majordomohome.com/
 * @version 0.3
 */
//
//
include_once(DIR_MODULES . 'terminals/terminals_media.inc.php');
include_once(DIR_MODULES . 'terminals/terminals_queue.inc.php');
include_once(DIR_MODULES . 'terminals/terminals_settings.inc.php');
include_once(DIR_MODULES . 'terminals/terminals_send.inc.php');
include_once(DIR_MODULES . 'terminals/terminals_auth.inc.php');

class terminals extends module
{
    // Очередь сообщений (terminals_queue.inc.php): срочный уровень, срок жизни сообщения и запас сторожа, с;
    // сколько раз по 2 с продлевать ожидание, если плеер ещё играет сообщение (isPlaying)
    const QUEUE_URGENT_LEVEL = 9;
    const QUEUE_TTL = 600;
    const QUEUE_WATCHDOG = 30;
    const QUEUE_PLAYING_CHECKS = 15;
    // сигнал перед сообщением — если с конца прошлого сообщения прошло не меньше стольких секунд
    const QUEUE_SIGNAL_PAUSE = 20;

    /**
     * terminals
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "terminals";
        $this->title = "<#LANG_MODULE_TERMINALS#>";
        $this->module_category = "<#LANG_SECTION_SETTINGS#>";
        $this->checkInstalled();
        $this->serverip = getLocalIp();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 1)
    {
        $data = array();
        if (IsSet($this->id)) {
            $data["id"] = $this->id;
        }
        if (IsSet($this->view_mode)) {
            $data["view_mode"] = $this->view_mode;
        }
        if (IsSet($this->edit_mode)) {
            $data["edit_mode"] = $this->edit_mode;
        }
        if (IsSet($this->tab)) {
            $data["tab"] = $this->tab;
        }
        return parent::saveParams($data);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams($data = 1)
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        global $session;
        $out = array();
        terminalsLoadLanguage();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (IsSet($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (IsSet($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {
        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }
        if ($this->data_source == 'terminals' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_terminals') {
                $this->search_terminals($out);
            }
            if ($this->view_mode == 'edit_terminals') {
                $this->edit_terminals($out, $this->id);
            }
            if ($this->view_mode == 'delete_terminals') {
                $this->delete_terminals($this->id);
                $this->redirect("?");
            }
        }
    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        // страница «Сообщения»: обмен сообщениями между терминалами (terminals_messages.inc.php)
        include DIR_MODULES . 'terminals/terminals_messages.inc.php';
    }

    /**
     * terminals search
     *
     * @access public
     */
    function search_terminals(&$out)
    {
        require(DIR_MODULES . $this->name . '/terminals_search.inc.php');
    }

    /**
     * terminals edit/add
     *
     * @access public
     */
    function edit_terminals(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/terminals_edit.inc.php');
    }

    /**
     * terminals delete record
     *
     * @access public
     */
    function delete_terminals($id)
    {
        if ($rec = getTerminalByID($id)) {
            SQLExec('DELETE FROM `terminals` WHERE `ID` = ' . $rec['ID']);
            SQLExec('DELETE FROM `terminals_queue` WHERE `TERMINAL_ID` = ' . (int)$rec['ID']);
            SQLExec('DELETE FROM `terminals_state` WHERE `TERMINAL_ID` = ' . (int)$rec['ID']);
            SQLExec('DELETE FROM `terminals_tokens` WHERE `TERMINAL_ID` = ' . (int)$rec['ID']);
        }
    }


    function terminalSayByCache($terminal_rec, $cached_filename, $level)
    {
        $min_level = getGlobal('ThisComputer.minMsgLevel');
        if ($terminal_rec['MIN_MSG_LEVEL']) {
            $min_level = (int)processTitle($terminal_rec['MIN_MSG_LEVEL']);
        }
        if ($level < $min_level) {
            return false;
        }
        if ($terminal_rec['MAJORDROID_API'] || $terminal_rec['PLAYER_TYPE'] == 'ghn') {
            return;
        }
        if ($terminal_rec['CANPLAY'] && $terminal_rec['PLAYER_TYPE'] != '') {
            if (preg_match('/\/cms\/cached.+/', $cached_filename, $m)) {
                $server_ip = getLocalIp();
                if (!$server_ip) {
                    DebMes("Server IP not found", 'terminals');
                    return false;
                } else {
                    $cached_filename = 'http://' . $server_ip . $m[0];
                }
            } else {
                DebMes("Unknown file path format: " . $cached_filename, 'terminals');
                return false;
            }
            DebMes("Playing cached to " . $terminal_rec['TITLE'] . ' (level ' . $level . '): ' . $cached_filename, 'terminals');
            playMedia($cached_filename, $terminal_rec['TITLE']);
        }
    }

    // $message_id — номер сообщения страницы «Сообщения» (terminals_messages): отметка «доставлено»;
    // $silent — текст или картинка без озвучки: в очереди не задерживает следующие сообщения
    function terminalSay($terminal_rec, $message, $level, $image = '', $sender = '', $message_id = 0, $silent = false)
    {
        $asking = 0;
        if ($level === 'ask') {
            $level = 9999;
            $asking = 1;
        }
        $min_level = getGlobal('ThisComputer.minMsgLevel');
        if ($terminal_rec['MIN_MSG_LEVEL']) {
            $min_level = (int)processTitle($terminal_rec['MIN_MSG_LEVEL']);
        }
        if ($level < $min_level) {
            return false;
        }
        if (terminalsQueueUsed(terminalsQueueAddon($terminal_rec))) {
            return terminalsQueueAdd($terminal_rec, $asking ? 'ask' : ($silent ? 'text' : 'say'), $message, $level, '', $image, $sender, $message_id);
        }
        DebMes("Saying to " . $terminal_rec['TITLE'] . ' (level ' . $level . '): ' . $message, 'terminals');
        include_once DIR_MODULES . 'terminals/tts_addon.class.php';
        $addon_file = DIR_MODULES . 'terminals/tts/' . $terminal_rec['TTS_TYPE'] . '.addon.php';
        if (file_exists($addon_file)) {
            include_once($addon_file);
            $tts = new $terminal_rec['TTS_TYPE']($terminal_rec);
            if (($image != '' || $sender != '') && method_exists($tts, 'showMessage')) {
                // сообщение с картинкой или от другого терминала — дополнению, которое умеет его показать
                $result = $tts->showMessage($message, $level, $image, '', $sender);
            } elseif ($asking) {
                $result = $tts->ask($message, $level);
            } else {
                $result = $tts->say($message, $level);
            }
            // дополнение без очереди приняло сообщение — доставлено
            if ($message_id && $result !== false && function_exists('terminalsMessagesDelivered')) {
                terminalsMessagesDelivered($message_id);
            }
        } else {
            DebMes("Could not find $addon_file", 'terminals');
        }
        return $result;
    }

    /**
     * terminals subscription events
     *
     * @access public
     */
    function processSubscription($event, $details = '')
    {
        $this->getConfig();
        //DebMes("Processing $event: " . json_encode($details, JSON_UNESCAPED_UNICODE), 'terminals');
        if ($event == 'SAY') {
            $terminals = SQLSelect("SELECT * FROM terminals WHERE CANTTS=1 AND TTS_TYPE!=''");
            foreach ($terminals as $terminal_rec) {
                // картинка из say('… image:путь') — в событии SAY
                $this->terminalSay($terminal_rec, $details['message'], $details['level'], isset($details['image']) ? (string)$details['image'] : '');
            }
        } elseif ($event == 'SAYTO' || $event == 'ASK') {
            $terminal_rec = array();
            if ($details['destination']) {
                if (!$terminal_rec = getTerminalsByName($details['destination'], 1)[0]) {
                    $terminal_rec = getTerminalsByHost($details['destination'], 1)[0];
                }
            }
            if (!$terminal_rec['ID']) {
                return false;
            }
            if (!$terminal_rec['CANTTS'] || $terminal_rec['TTS_TYPE']=='') {
                return false;
            }
            if ($event == 'ASK') {
                $details['level'] = 'ask';
            }
            // картинка и отправитель — от terminalsSend()
            $this->terminalSay($terminal_rec, $details['message'], $details['level'],
                isset($details['image']) ? (string)$details['image'] : '', isset($details['from']) ? (string)$details['from'] : '',
                isset($details['message_id']) ? (int)$details['message_id'] : 0);
        } elseif ($event == 'SAY_CACHED_READY') {
            $filename = $details['filename'];
            if (!file_exists($filename)) return false;
            if ($details['event']) {
                $source_event = $details['event'];
            } else {
                $source_event = 'SAY';
            }
            if ($source_event == 'SAY') {
                $terminals = SQLSelect("SELECT * FROM terminals WHERE CANTTS=1");
                foreach ($terminals as $terminal_rec) {
                    //$this->terminalSayByCache($terminal_rec, $filename, $details['level']);
                    $this->terminalSayByCacheQueue($terminal_rec, $details['level'], $filename, $details['message']);
                }
            } elseif ($source_event == 'SAYTO' || $source_event == 'ASK') {
                $terminal_rec = array();
                if ($details['destination']) {
                    if (!$terminal_rec = getTerminalsByName($details['destination'], 1)[0]) {
                        $terminal_rec = getTerminalsByHost($details['destination'], 1)[0];
                    }
                }
                if (!$terminal_rec['ID']) {
                    return false;
                }
                if ($source_event == 'ASK') {
                    $details['level'] = 9999;
                }
                //$this->terminalSayByCache($terminal_rec, $filename, $details['level']);
                $this->terminalSayByCacheQueue($terminal_rec, $details['level'], $filename, $details['message']);
            }

        } elseif ($event == 'HOURLY') {
            // check terminals
            $terminals = SQLSelect("SELECT * FROM terminals WHERE IS_ONLINE=0 AND HOST!=''");
            foreach ($terminals as $terminal) {
                if (ping($terminal['HOST'])) {
                    $terminal['LATEST_ACTIVITY'] = date('Y-m-d H:i:s');
                    $terminal['IS_ONLINE'] = 1;
                    SQLUpdate('terminals', $terminal);
                }
            }
            SQLExec('UPDATE terminals SET IS_ONLINE=0 WHERE LATEST_ACTIVITY < (NOW() - INTERVAL 90 MINUTE)'); //
        } elseif ($event == 'MINUTELY') {
            terminalsQueueWatchdog();
            // переписка страницы «Сообщения»: чистка раз в сутки
            if (!isset($this->config['MESSAGES_CLEANUP_DAY']) || $this->config['MESSAGES_CLEANUP_DAY'] != date('Y-m-d')) {
                $this->config['MESSAGES_CLEANUP_DAY'] = date('Y-m-d');
                $this->saveConfig();
                terminalsMessagesCleanup(terminalsHistoryDays($this->config));
            }
        } elseif ($event == 'startup') {
            terminalsQueueReset();
        } elseif ($event == 'SAYREPLY') {
        }
    }

    /**
     * очередь сообщений
     *
     * @access public
     */
    function terminalSayByCacheQueue($terminal_rec, $level, $cached_filename, $message)
    {

        $min_level = getGlobal('ThisComputer.minMsgLevel');
        if ($terminal_rec['MIN_MSG_LEVEL']) {
            $min_level = (int)processTitle($terminal_rec['MIN_MSG_LEVEL']);
        }
        if ($level < $min_level) {
            return false;
        }
        if (terminalsQueueUsed(terminalsQueueAddon($terminal_rec))) {
            return terminalsQueueAdd($terminal_rec, 'cached', $message, $level, $cached_filename);
        }
        DebMes("Saying cached to " . $terminal_rec['TITLE'] . ' (level ' . $level . '): ' . $message . " (file: $cached_filename)", 'terminals');
        $result = false;
        include_once DIR_MODULES . 'terminals/tts_addon.class.php';
        $addon_file = DIR_MODULES . 'terminals/tts/' . $terminal_rec['TTS_TYPE'] . '.addon.php';
        if (file_exists($addon_file)) {
            include_once($addon_file);
            $tts = new $terminal_rec['TTS_TYPE']($terminal_rec);
            $result = $tts->sayCached($message, $level, $cached_filename);
        } else {
            DebMes("Could not find $addon_file", 'terminals');
        }
        return $result;
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($parent_name = "")
    {
        subscribeToEvent($this->name, 'SAY', '', 0);
        subscribeToEvent($this->name, 'SAYREPLY', '', 0);
        subscribeToEvent($this->name, 'SAYTO', '', 0);
        subscribeToEvent($this->name, 'ASK', '', 0);
        subscribeToEvent($this->name, 'SAY_CACHED_READY', 0);
        subscribeToEvent($this->name, 'HOURLY');
        subscribeToEvent($this->name, 'MINUTELY');
        subscribeToEvent($this->name, 'startup');
        // прежняя очередь дополнения mediaplayer (задания allsay-target-*) больше не используется
        SQLExec("DELETE FROM jobs WHERE TITLE LIKE 'allsay-target-%'");
        parent::install($parent_name);

    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLDropTable('terminals');
        SQLDropTable('terminals_queue');
        SQLDropTable('terminals_state');
        SQLDropTable('terminals_messages');
        SQLDropTable('terminals_groups');
        SQLDropTable('terminals_colors');
        SQLDropTable('terminals_tokens');
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYTO');
        unsubscribeFromEvent($this->name, 'ASK');
        unsubscribeFromEvent($this->name, 'SAYREPLY');
        unsubscribeFromEvent($this->name, 'SAY_CACHED_READY');
        unsubscribeFromEvent($this->name, 'HOURLY');
        unsubscribeFromEvent($this->name, 'MINUTELY');
        unsubscribeFromEvent($this->name, 'startup');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {
        /*
        terminals - Terminals
        */
        $data = <<<EOD
 terminals: ID int(10) unsigned NOT NULL auto_increment
 terminals: NAME varchar(255) NOT NULL DEFAULT ''
 terminals: HOST varchar(255) NOT NULL DEFAULT ''
 terminals: TITLE varchar(255) NOT NULL DEFAULT ''
 terminals: CANPLAY int(3) NOT NULL DEFAULT '0'
 terminals: CANTTS int(3) NOT NULL DEFAULT '0'
 terminals: MIN_MSG_LEVEL varchar(255) NOT NULL DEFAULT ''
 terminals: TTS_TYPE char(20) NOT NULL DEFAULT '' 
 terminals: PLAYER_TYPE char(20) NOT NULL DEFAULT ''
 terminals: PLAYER_PORT varchar(255) NOT NULL DEFAULT ''
 terminals: PLAYER_USERNAME varchar(255) NOT NULL DEFAULT ''
 terminals: PLAYER_PASSWORD varchar(255) NOT NULL DEFAULT ''
 terminals: PLAYER_CONTROL_ADDRESS varchar(255) NOT NULL DEFAULT ''
 terminals: IS_ONLINE int(3) NOT NULL DEFAULT '0'
 terminals: MAJORDROID_API int(3) NOT NULL DEFAULT '0'
 terminals: LATEST_REQUEST varchar(255) NOT NULL DEFAULT ''
 terminals: LATEST_REQUEST_TIME datetime
 terminals: LATEST_ACTIVITY datetime
 terminals: LINKED_OBJECT varchar(255) NOT NULL DEFAULT ''
 terminals: LEVEL_LINKED_PROPERTY varchar(255) NOT NULL DEFAULT ''
 terminals: LOCATION_ID int(5) NOT NULL DEFAULT '0' 
 terminals: TTS_SETTINGS text
 terminals: PLAYER_SETTINGS text

 terminals_queue: ID int(10) unsigned NOT NULL auto_increment
 terminals_queue: TERMINAL_ID int(10) NOT NULL DEFAULT '0'
 terminals_queue: KIND char(10) NOT NULL DEFAULT ''
 terminals_queue: MESSAGE text
 terminals_queue: LEVEL int(10) NOT NULL DEFAULT '0'
 terminals_queue: FILENAME varchar(255) NOT NULL DEFAULT ''
 terminals_queue: IMAGE varchar(255) NOT NULL DEFAULT ''
 terminals_queue: SENDER varchar(255) NOT NULL DEFAULT ''
 terminals_queue: MESSAGE_IDS varchar(255) NOT NULL DEFAULT ''
 terminals_queue: DURATION int(10) NOT NULL DEFAULT '0'
 terminals_queue: ADDED int(10) NOT NULL DEFAULT '0'
 terminals_queue: STARTED int(10) NOT NULL DEFAULT '0'
 terminals_queue: FINISH int(10) NOT NULL DEFAULT '0'
 terminals_queue: INDEX (TERMINAL_ID)

 terminals_state: ID int(10) unsigned NOT NULL auto_increment
 terminals_state: TERMINAL_ID int(10) NOT NULL DEFAULT '0'
 terminals_state: PLAYER_STATE text
 terminals_state: SAVED int(10) NOT NULL DEFAULT '0'
 terminals_state: INDEX (TERMINAL_ID)

 terminals_messages: ID int(10) unsigned NOT NULL auto_increment
 terminals_messages: FROM_NAME varchar(255) NOT NULL DEFAULT ''
 terminals_messages: TO_NAME varchar(255) NOT NULL DEFAULT ''
 terminals_messages: MESSAGE text
 terminals_messages: IMAGE varchar(255) NOT NULL DEFAULT ''
 terminals_messages: SOUND varchar(255) NOT NULL DEFAULT ''
 terminals_messages: ADDED int(10) NOT NULL DEFAULT '0'
 terminals_messages: VIDEO varchar(255) NOT NULL DEFAULT ''
 terminals_messages: FILE varchar(255) NOT NULL DEFAULT ''
 terminals_messages: FILE_NAME varchar(255) NOT NULL DEFAULT ''
 terminals_messages: FORWARDED varchar(255) NOT NULL DEFAULT ''
 terminals_messages: CHAT varchar(50) NOT NULL DEFAULT ''
 terminals_messages: SEND_ID varchar(32) NOT NULL DEFAULT ''
 terminals_messages: DELIVERED int(10) NOT NULL DEFAULT '0'
 terminals_messages: READ_AT int(10) NOT NULL DEFAULT '0'
 terminals_messages: FROM_ID int(10) NOT NULL DEFAULT '0'
 terminals_messages: TO_ID int(10) NOT NULL DEFAULT '0'
 terminals_messages: FROM_USER int(10) NOT NULL DEFAULT '0'
 terminals_messages: TO_USER int(10) NOT NULL DEFAULT '0'
 terminals_messages: HIDE_FROM int(3) NOT NULL DEFAULT '0'
 terminals_messages: HIDE_TO int(3) NOT NULL DEFAULT '0'
 terminals_messages: INDEX (TO_NAME)
 terminals_messages: INDEX (TO_ID)
 terminals_messages: INDEX (FROM_ID)
 terminals_messages: INDEX (TO_USER)

 terminals_tokens: ID int(10) unsigned NOT NULL auto_increment
 terminals_tokens: TERMINAL_ID int(10) NOT NULL DEFAULT '0'
 terminals_tokens: USER_ID int(10) NOT NULL DEFAULT '0'
 terminals_tokens: TOKEN varchar(64) NOT NULL DEFAULT ''
 terminals_tokens: REQUIRED int(3) NOT NULL DEFAULT '0'
 terminals_tokens: CREATED int(10) NOT NULL DEFAULT '0'
 terminals_tokens: LAST_USED int(10) NOT NULL DEFAULT '0'
 terminals_tokens: INDEX (TERMINAL_ID)
 terminals_tokens: INDEX (USER_ID)
 terminals_tokens: INDEX (TOKEN)

 terminals_colors: ID int(10) unsigned NOT NULL auto_increment
 terminals_colors: CHAT_KEY varchar(100) NOT NULL DEFAULT ''
 terminals_colors: COLOR varchar(10) NOT NULL DEFAULT ''
 terminals_colors: EMOJI varchar(100) NOT NULL DEFAULT ''

 terminals_groups: ID int(10) unsigned NOT NULL auto_increment
 terminals_groups: TITLE varchar(255) NOT NULL DEFAULT ''
 terminals_groups: MEMBERS text
 terminals_groups: NOTE text
 terminals_groups: ADDED int(10) NOT NULL DEFAULT '0'
EOD;
        parent::dbInstall($data);

        $terminals = SQLSelect("SELECT * FROM terminals WHERE TTS_TYPE='' AND CANTTS=1");
        foreach ($terminals as $terminal) {
            if ($terminal['MAJORDROID_API']) {
                $terminal['TTS_TYPE'] = 'majordroid';
            } else {
                $terminal['TTS_TYPE'] = 'mediaplayer';
            }
            SQLUpdate('terminals', $terminal);
        }

        // Отдельные типы TTS для плееров (mpd_tts, lms_tts, kodi_tts, chromecast_tts, dnla_tts) объединены
        // в mediaplayer: он сам учитывает тип плеера. Терминалам — mediaplayer с их прежними настройками,
        // файлы прежних типов (только эти, наследники mediaplayer) — удалить, чтобы не было в списке типов
        $merged = array('mpd_tts', 'lms_tts', 'kodi_tts', 'chromecast_tts', 'dnla_tts');
        foreach (SQLSelect("SELECT ID, TTS_TYPE, TTS_SETTINGS FROM terminals WHERE TTS_TYPE IN ('" . implode("','", $merged) . "')") as $terminal) {
            $settings = json_decode((string)$terminal['TTS_SETTINGS'], true);
            $settings = is_array($settings) ? $settings : array();
            if (!isset($settings['mediaplayer']) && isset($settings[$terminal['TTS_TYPE']])) {
                $settings['mediaplayer'] = $settings[$terminal['TTS_TYPE']];
            }
            SQLUpdate('terminals', array('ID' => (int)$terminal['ID'], 'TTS_TYPE' => 'mediaplayer', 'TTS_SETTINGS' => json_encode($settings)));
        }
        foreach ($merged as $name) {
            $file = DIR_MODULES . 'terminals/tts/' . $name . '.addon.php';
            if (is_file($file) && strpos((string)@file_get_contents($file), 'class ' . $name . ' extends mediaplayer') !== false) {
                @unlink($file);
            }
        }

        // ключ доступа к странице «Сообщения» — каждому терминалу (terminals_auth.inc.php)
        terminalsTokenEnsureAll();
        // переписка «Сообщений» узнаёт терминалы по ID (переименование не теряет историю): прежним записям —
        // ID по имени терминала
        SQLExec("UPDATE terminals_messages m JOIN terminals t ON t.NAME=m.TO_NAME SET m.TO_ID=t.ID WHERE m.TO_ID=0");
        SQLExec("UPDATE terminals_messages m JOIN terminals t ON t.NAME=m.FROM_NAME SET m.FROM_ID=t.ID WHERE m.FROM_ID=0");
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgTWFyIDI3LCAyMDA5IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
