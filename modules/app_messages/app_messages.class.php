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
            // в панели управления — ссылка на страницу и настройка вебсокета для внешнего доступа
            terminalsLoadLanguage();
            $terminals->getConfig();
            $saved = false;
            if (gr('ws_save')) {
                $terminals->config['MESSAGES_WS_EXTERNAL'] = terminalsWsUrl(gr('ws_external'));
                $terminals->saveConfig();
                $saved = true;
            }
            $value = isset($terminals->config['MESSAGES_WS_EXTERNAL']) ? (string)$terminals->config['MESSAGES_WS_EXTERNAL'] : '';
            $url = ROOTHTML . 'popup/app_messages.html';
            $h = function ($text) {
                return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
            };
            $this->result = '<p>' . terminalsLang('TERMINALS_APP_PAGE') . ': <a href="' . $url . '" target="_blank">' . $url . '</a></p>'
                . '<form method="post" action="?" class="form-horizontal" style="max-width:720px">'
                . '<input type="hidden" name="ws_save" value="1">'
                . '<div class="form-group"><label class="control-label col-sm-4">' . $h(terminalsLang('TERMINALS_WS_EXTERNAL')) . '</label>'
                . '<div class="col-sm-8"><input type="text" name="ws_external" class="form-control" value="' . $h($value) . '"'
                . ' placeholder="wss://home.example.com/majordomo">'
                . '<span class="help-block">' . terminalsLang('TERMINALS_WS_EXTERNAL_HELP') . '</span></div></div>'
                . '<div class="form-group"><div class="col-sm-offset-4 col-sm-8"><button type="submit" class="btn btn-primary">'
                . $h(terminalsLang('TERMINALS_SAVE')) . '</button>'
                . ($saved ? ' <span class="text-success">&#10003;</span>' : '') . '</div></div></form>';
            // как шаблоны модулей: action="?" формы — в адрес панели с этим модулем (pd, md), иначе
            // форма уходит на admin.php без модуля и настройка не сохраняется
            $this->result = $this->parseLinks($this->result);
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
