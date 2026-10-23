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
            if (gr('ws_save')) {
                $terminals->config['MESSAGES_WS_EXTERNAL'] = terminalsWsUrl(gr('ws_external'));
                $terminals->config['MESSAGES_HISTORY_DAYS'] = (string)max(0, min(3650, (int)gr('history_days')));
                // прежние общие настройки ключей больше не используются: «Только по ключу» — у каждого терминала
                unset($terminals->config['MESSAGES_TOKEN_ONLY'], $terminals->config['MESSAGES_USE_TOKENS']);
                $terminals->saveConfig();
                $out['SAVED'] = 1;
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
