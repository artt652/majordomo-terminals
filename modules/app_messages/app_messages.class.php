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

    function run()
    {
        $terminals = new terminals();
        if ($this->action == 'admin') {
            // в панели управления — ссылка на страницу
            terminalsLoadLanguage();
            $url = ROOTHTML . 'popup/app_messages.html';
            $this->result = '<p>' . terminalsLang('TERMINALS_APP_PAGE') . ': <a href="' . $url . '" target="_blank">' . $url . '</a></p>';
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
