<?php
/**
 * Сообщения между терминалами — приложение (app_*).
 *
 * Тонкая обёртка над страницей «Сообщения» модуля terminals (terminals_messages.inc.php):
 * как приложение MajorDoMo она открывается по /popup/app_messages.html, /module/app_messages.html
 * и /apps/app_messages.html и показывается в списке приложений (файл-признак modules/app_messages/app).
 * Вся логика и шаблон — в модуле terminals.
 */

class app_messages extends module
{
    function __construct()
    {
        $this->name = 'app_messages';
        include_once(DIR_MODULES . 'terminals/terminals.class.php');
        $this->title = terminalsLang('TERMINALS_MESSAGES');
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    // Параметры модуля для ссылок и форм панели управления (как у остальных модулей): без этого
    // parseLinks() не передаёт в pd состояние панели (action=app_messages), и форма настроек,
    // отправленная из панели, попадает на стартовую страницу — модуль не вызывается
    function saveParams($data = 1)
    {
        return parent::saveParams(array());
    }

    function run()
    {
        $terminals = new terminals();
        if ($this->action == 'admin') {
            // в панели управления — ссылка на страницу, вебсокет для внешнего доступа, срок истории, ключи пользователей
            terminalsLoadLanguage();
            $terminals->getConfig();
            $out = array();
            // вкладки: «Настройки» (общие, ключи пользователей) и «Камеры (go2rtc)» (настройки go2rtc, права на камеры);
            // у каждой — своя форма: сохранение одной не трогает поля другой
            $out['TAB'] = (gr('go2rtc_save') || gr('access_save')) ? 'tab_cameras' : '';
            if (gr('ws_save')) {
                $terminals->config['MESSAGES_WS_EXTERNAL'] = terminalsWsUrl(gr('ws_external'));
                $terminals->config['MESSAGES_HISTORY_DAYS'] = (string)max(0, min(3650, (int)gr('history_days')));
                // прежние общие настройки ключей больше не используются: «Только по ключу» — у каждого терминала
                unset($terminals->config['MESSAGES_TOKEN_ONLY'], $terminals->config['MESSAGES_USE_TOKENS']);
                $terminals->saveConfig();
                $out['SAVED'] = 1;
            }
            if (gr('go2rtc_save')) {
                // камеры go2rtc (terminals_go2rtc.inc.php)
                foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_USER', 'GO2RTC_RTSP_PASSWORD') as $key) {
                    $terminals->config[$key] = trim((string)gr(strtolower($key)));
                }
                foreach (array('GO2RTC_URL', 'GO2RTC_DEVICE_URL') as $key) {
                    if ($terminals->config[$key] != '' && !preg_match('#^https?://#i', $terminals->config[$key])) {
                        $terminals->config[$key] = 'http://' . $terminals->config[$key];
                    }
                }
                $terminals->config['GO2RTC_RTSP_PORT'] = gr('go2rtc_rtsp_port', 'int') ? (string)gr('go2rtc_rtsp_port', 'int') : '';
                $terminals->config['GO2RTC_WEBTORRENT'] = gr('go2rtc_webtorrent', 'int') ? '1' : '0';
                $terminals->config['GO2RTC_WT_MINUTES'] = gr('go2rtc_wt_minutes', 'int') ? (string)max(1, min(1440, gr('go2rtc_wt_minutes', 'int'))) : '';
                $terminals->config['GO2RTC_WAIT'] = gr('go2rtc_wait', 'int') ? (string)max(2, min(25, gr('go2rtc_wait', 'int'))) : '';
                $terminals->saveConfig();
                $out['GO2RTC_SAVED'] = 1;
            }
            // права на камеры: камера => все / терминалы / пользователи (без записи — только администратору)
            if (gr('access_save')) {
                $posted = gr('access');
                $posted = is_array($posted) ? $posted : array();
                $access = array();
                foreach (terminalsGo2rtcStreams() as $name) {
                    $key = md5($name);
                    $rule = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();
                    $item = array(
                        'all' => !empty($rule['all']) ? 1 : 0,
                        'terminals' => array_values(array_filter(isset($rule['terminals']) ? (array)$rule['terminals'] : array(), 'is_string')),
                        'users' => array_values(array_unique(array_map('intval', isset($rule['users']) ? (array)$rule['users'] : array()))),
                    );
                    if ($item['all'] || count($item['terminals']) || count($item['users'])) {
                        $access[$name] = $item;
                    }
                }
                terminalsCameraAccessSave($access);
                $out['ACCESS_SAVED'] = 1;
            }
            $user_id = gr('user_key_create', 'int');
            if ($user_id && SQLSelectOne("SELECT ID FROM users WHERE ID=" . (int)$user_id)) {
                terminalsUserTokenCreate($user_id);
                $out['KEY_RECREATED'] = 1;
            }
            $h = function ($text) {
                return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
            };
            $out['PAGE_URL'] = ROOTHTML . 'popup/app_messages.html';
            $out['WS_EXTERNAL'] = $h(isset($terminals->config['MESSAGES_WS_EXTERNAL']) ? $terminals->config['MESSAGES_WS_EXTERNAL'] : '');
            $out['HISTORY_DAYS'] = terminalsHistoryDays($terminals->config);
            // камеры go2rtc: настройки и состояние (сколько потоков видно)
            $terminals->getConfig();
            foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_PORT', 'GO2RTC_RTSP_USER',
                'GO2RTC_RTSP_PASSWORD', 'GO2RTC_WT_MINUTES', 'GO2RTC_WAIT') as $key) {
                $out[$key] = $h(isset($terminals->config[$key]) ? $terminals->config[$key] : '');
            }
            $out['GO2RTC_WEBTORRENT'] = !empty($terminals->config['GO2RTC_WEBTORRENT']) ? 1 : 0;
            $out['CAMERAS'] = array();
            if (terminalsGo2rtcEnabled()) {
                $streams = terminalsGo2rtcStreams();
                $out['GO2RTC_STATUS'] = $h(sprintf(terminalsLang('TERMINALS_GO2RTC_STREAMS'), count($streams)));
                $out['GO2RTC_DEVICE_AUTO'] = $h(terminalsGo2rtcDeviceBase());
                $access = terminalsCameraAccess();
                $all_terms = SQLSelect("SELECT NAME, TITLE FROM terminals ORDER BY TITLE");
                $all_users = SQLSelect("SELECT ID, NAME, USERNAME FROM users ORDER BY NAME");
                foreach ($streams as $name) {
                    $rule = isset($access[$name]) ? $access[$name] : array();
                    $camera = array('NAME' => $h($name), 'KEY' => md5($name), 'ALL' => !empty($rule['all']) ? 1 : 0,
                        'TERMINALS' => array(), 'USERS' => array());
                    foreach ($all_terms as $t) {
                        $camera['TERMINALS'][] = array('KEY' => $camera['KEY'], 'NAME' => $h($t['NAME']), 'TITLE' => $h($t['TITLE']),
                            'ON' => (!empty($rule['terminals']) && in_array($t['NAME'], (array)$rule['terminals'], true)) ? 1 : 0);
                    }
                    foreach ($all_users as $u) {
                        $camera['USERS'][] = array('KEY' => $camera['KEY'], 'ID' => (int)$u['ID'], 'TITLE' => $h($u['NAME'] != '' ? $u['NAME'] : $u['USERNAME']),
                            'ON' => (!empty($rule['users']) && in_array((int)$u['ID'], array_map('intval', (array)$rule['users']), true)) ? 1 : 0);
                    }
                    $out['CAMERAS'][] = $camera;
                }
            }
            $out['HAS_CAMERAS'] = count($out['CAMERAS']) ? 1 : 0;
            // ключ каждому пользователю (создаётся при первом показе)
            $out['USERS'] = array();
            foreach (SQLSelect("SELECT * FROM users ORDER BY NAME") as $user) {
                $token = terminalsUserTokenGet($user['ID']);
                $row = terminalsUserTokenRow($user['ID']);
                $out['USERS'][] = array(
                    'ID' => (int)$user['ID'],
                    'TITLE' => $h($user['NAME'] != '' ? $user['NAME'] : $user['USERNAME']),
                    'USERNAME' => $h($user['USERNAME']),
                    'HAS_PASSWORD' => terminalsUserHasPassword($user) ? 1 : 0,
                    'TOKEN' => $token,
                    'CREATED' => !empty($row['CREATED']) ? date('d.m.Y H:i', (int)$row['CREATED']) : '',
                    'LAST_USED' => !empty($row['LAST_USED']) ? date('d.m.Y H:i', (int)$row['LAST_USED']) : '',
                );
            }
            // шаблон модуля; парсер сам передаёт в ссылки и формы (action="?") адрес панели с этим модулем (pd, md)
            $this->data = $out;
            $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
            $this->result = $p->result;
            return;
        }
        $terminals->action = '';
        $terminals->mode = gr('mode');
        $terminals->run();
        $this->result = $terminals->result;
    }

    function install($parent_name = '')
    {
        parent::install($parent_name);
    }
}
