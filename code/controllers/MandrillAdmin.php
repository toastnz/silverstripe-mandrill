<?php

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldFooter;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\Core\Convert;
use SilverStripe\View\Requirements;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\Form;
use SilverStripe\Control\Session;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormAction;
use SilverStripe\CMS\Search\SearchForm;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Security\Permission;
use SilverStripe\View\ArrayData;
use SilverStripe\Control\Director;
use SilverStripe\Forms\LiteralField;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Security\PermissionProvider;

/**
 * Mandrill admin section
 *
 * Allow you to see messages sent through the api key used to send messages
 *
 * @package Mandrill
 * @author LeKoala <thomas@lekoala.be>
 */
class MandrillAdmin extends LeftAndMain implements PermissionProvider
{

    const MESSAGE_TAG = 'message';
    const MESSAGE_CACHE_MINUTES = 5;
    const WEBHOOK_TAG = 'webhook';
    const WEBHOOK_CACHE_MINUTES = 1440; // 1 day

    private static $menu_title = "Mandrill";
    private static $url_segment = "mandrill";

    private static $menu_icon = "mandrill/images/icon.png";

    private static $url_rule = '/$Action/$ID';
    private static $allowed_actions = array(
        "view",
        'view_message',
        "ListForm",
        "SearchForm",
        "doSearch",
        "InstallHookForm",
        "doInstallHook",
        "UninstallHookForm",
        "doUninstallHook"
    );
    private static $cache_enabled = true;

    /**
     * @var MandrillMessage
     */
    protected $currentMessage;

    /**
     * @var string
     */
    protected $view = '_Content';
    protected $mandrillError = null;

    public function init()
    {
        parent::init();
    }

    public function Content()
    {
        return $this->renderWith($this->getTemplatesWithSuffix($this->view));
    }

    public function index($request)
    {
        return parent::index($request);
    }

    /**
     * @return MandrillMailer
     * @throws Exception
     */
    public function getMailer()
    {
        $mailer = Injector::inst()->get(Mailer::class);
        if (get_class($mailer) != MandrillMailer::class) {
            throw new Exception('This class require to use MandrillMailer');
        }
        return $mailer;
    }

    /**
     * @return Mandrill
     */
    public function getMandrill()
    {
        return $this->getMailer()->getMandrill();
    }

    /**
     * @return MandrillMessage
     */
    public function CurrentMessage()
    {
        return $this->currentMessage;
    }

    public function view($request)
    {
        $id = $this->getRequest()->param('ID');
        if (!$id) {
            return $this->httpError(404);
        }
        $this->currentMessage = $this->MessageInfo($id);
        return $this->getResponseNegotiator()->respond($request);
    }

    public function view_message()
    {
        $id = $this->getRequest()->param('ID');
        if (!$id) {
            return $this->httpError(404);
        }
        $this->currentMessage = $this->MessageInfo($id);
        echo $this->currentMessage->html;
        die();
    }

    /**
     * Returns a GridField of messages
     * @return Form
     */
    public function ListForm()
    {
        $fields = new FieldList();
        $gridFieldConfig = GridFieldConfig::create()->addComponents(
            new GridFieldToolbarHeader(), new GridFieldSortableHeader(), new GridFieldDataColumns(), new GridFieldFooter()
        );
        $gridField = new GridField('SearchResults', _t('MandrillAdmin.SearchResults', 'Search Results'), $this->Messages(), $gridFieldConfig);
        $columns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $columns->setDisplayFields(array(
            'date' => _t('MandrillAdmin.MessageDate', DBDate::class),
            'state' => _t('MandrillAdmin.MessageStatus', 'Status'),
            'sender' => _t('MandrillAdmin.MessageSender', 'Sender'),
            'email' => _t('MandrillAdmin.MessageEmail', Email::class),
            'subject' => _t('MandrillAdmin.MessageSubject', 'Subject'),
            'opens' => _t('MandrillAdmin.MessageOpens', 'Opens'),
            'clicks' => _t('MandrillAdmin.MessageClicks', 'Clicks'),
        ));
        $columns->setFieldFormatting(array(
            'subject' => function ($value, &$item) {
                return sprintf(
                    '<a href="%s" class="cms-panel-link" data-pjax-target="Content">%s</a>', Convert::raw2xml($item->Link), $value
                );
            },
            'state' => function ($value, &$item) {
                $color = MandrillMessage::getColorForState($value);
                return sprintf('<span style="color:%s">%s</span>', $color, $value);
            }
        ));
        $gridField->addExtraClass('all-messages-gridfield');
        $fields->push($gridField);

        $actions = new FieldList();
        $form = Form::create(
                $this, "ListForm", $fields, $actions
            )->setHTMLID('Form_ListForm');
//        $form->setResponseNegotiator($this->getResponseNegotiator());

        return $form;
    }

    /**
     * @return CacheInterface
     */
    public function getCache()
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.MandrillAdmin');
        return $cache;
    }

    /**
     * @return boolean
     */
    public function getCacheEnabled()
    {
        $v = $this->config()->cache_enabled;
        if ($v === null) {
            $v = self::$cache_enabled;
        }
        return $v;
    }

    /**
     * List of MandrillMessage
     *
     * Messages are cached to avoid hammering the api
     *
     * @return \ArrayList
     */
    public function Messages()
    {
        $data = $this->getParams();
        if (isset($data['SecurityID'])) {
            unset($data['SecurityID']);
        }
        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = md5(serialize($data));
            $cache_result = $cache->get($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $list = unserialize($cache_result);
        } else {
            $defaultQuery = '*';
            // If we have a subaccount defined, we need to restrict the query to this subaccount
            if ($subaccount = MandrillMailer::getSubaccount()) {
                $defaultQuery = 'subaccount:' . $subaccount;
            }

            //search(string key, string query, string date_from, string date_to, array tags, array senders, array api_keys, integer limit)
            try {
                $messages = $this->getMandrill()->messages->search(
                    $this->getParam('Query', $defaultQuery), $this->getParam('DateFrom'), $this->getParam('DateTo'), null, null, array($this->getMandrill()->apikey), $this->getParam('Limit', 100)
                );
            } catch (Exception $ex) {
                Requirements::customScript("jQuery.noticeAdd({text: '" . $ex->getMessage() . "', type: 'error'});");
                $messages = array();
            }

            $list = new ArrayList();
            foreach ($messages as $message) {
                $m = new MandrillMessage($message);
                $list->push($m);
            }
            //5 minutes cache
            if ($cache_enabled && !empty($messages)) {
                $cache->set($cache_key, serialize($list), 60 * self::MESSAGE_CACHE_MINUTES);
            }
        }
        return $list;
    }

    /**
     * Get the detail of one message
     *
     * @param int $id
     * @return MandrillMessage
     */
    public function MessageInfo($id)
    {
        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = 'message_' . $id;
            $cache_result = $cache->get($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $message = unserialize($cache_result);
        } else {
            try {
                $info = $this->getMandrill()->messages->info($id);
                $content = $this->MessageContent($id);
                $info = array_merge($content, $info);
                $message = new MandrillMessage($info);
                //the detail is not going to change very often
                if ($cache_enabled) {
                    $cache->set($cache_key, serialize($message), 60 * 60);
                }
            } catch (Exception $ex) {
                $message = new MandrillMessage();
                $this->getCache()->clear('matchingTag', array(self::MESSAGE_TAG));
                SS_Log::log(get_class($ex) . ': ' . $ex->getMessage(), SS_LOG::DEBUG);
            }
        }
        return $message;
    }

    /**
     * Get the contnet of one message
     *
     * @param int $id
     * @return array
     */
    public function MessageContent($id)
    {
        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = 'content_' . $id;
            $cache_result = $cache->get($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $content = unserialize($cache_result);
        } else {
            try {
                $content = $this->getMandrill()->messages->content($id);
            } catch (Mandrill_Unknown_Message $ex) {
                $content = array();
                //the content is not available anymore
            }
            //if we have the content, store it forever since it's not available forever in the api
            if ($cache_enabled) {
                $cache->set($cache_key, serialize($content), 0);
            }
        }
        return $content;
    }

    public function doSearch($data, Form $form)
    {
        $values = array();
        foreach ($form->Fields() as $field) {
            $values[$field->getName()] = $field->datavalue();
        }
        // If we have a subaccount defined, we need to restrict the query to this subaccount
        if ($subaccount = MandrillMailer::getSubaccount()) {
            if (empty($values['Query'])) {
                $values['Query'] = 'subaccount:' . $subaccount;
            } else {
                $values['Query'] = $values['Query'] . ' AND subaccount:' . $subaccount;
            }
        }
        $session = $this->getRequest()->getSession();

        $session->set('MandrilAdminSearch', $values);
        $session->save($this->getRequest());
        return $this->redirectBack();
    }

    public function getParams()
    {
        $session = $this->getRequest()->getSession();

        return $session->get('MandrilAdminSearch');
    }

    public function getParam($name, $default = null)
    {
        $session = $this->getRequest()->getSession();

        $data = $session->get('MandrilAdminSearch');
        if (!$data) {
            return $default;
        }
        return (isset($data[$name]) && strlen($data[$name])) ? $data[$name] : $default;
    }

    public function SearchForm()
    {
        $fields = new FieldList();
        $fields->push(new DateField('DateFrom', _t('Mandrill.DATEFROM', 'From'), $this->getParam('DateFrom', date('Y-m-d', strtotime('-30 days')))));
        $fields->push(new DateField('DateTo', _t('Mandrill.DATETO', 'To'), $this->getParam('DateTo', date('Y-m-d'))));
        $fields->push($queryField = new TextField('Query', _t('Mandrill.QUERY', 'Query'), $this->getParam('Query')));
        $queryField->setAttribute('placeholder', 'full_email:joe@domain.* AND sender:me@company.com OR subject:welcome');
        $queryField->setDescription(_t('Mandrill.QUERYDESC', 'For more information about query syntax, please visit <a target="_blank" href="http://help.mandrill.com/entries/22211902">Mandrill Support</a>'));
        $fields->push(new DropdownField('Limit', _t('Mandrill.LIMIT', 'Limit'), [
            10   => 10,
            50   => 50,
            100  => 100,
            500  => 500,
            1000 => 1000
        ], $this->getParam('Limit', 100)));
        $actions = new FieldList();
        $actions->push(new FormAction('doSearch', _t('Mandrill.DOSEARCH', 'Search')));
        $form = new Form($this, 'SearchForm', $fields, $actions);

        $form->setFormMethod('get');

        $form->loadDataFrom($this->getRequest()->getVars());

        $this->extend('updateSearchForm', $form);
        return $form;
    }

    /**
     * Provides custom permissions to the Security section
     *
     * @return array
     */
    public function providePermissions()
    {
        $title = _t("Mandrill.MENUTITLE", LeftAndMain::menu_title_for_class('Mandrill'));
        return array(
            "CMS_ACCESS_Mandrill" => array(
                'name' => _t('Mandrill.ACCESS', "Access to '{title}' section", array('title' => $title)),
                'category' => _t('Permission.CMS_ACCESS_CATEGORY', 'CMS Access'),
                'help' => _t(
                    'Mandrill.ACCESS_HELP', 'Allow use of Mandrill admin section'
                )
            ),
        );
    }

    /**
     * A template accessor to check the ADMIN permission
     *
     * @return bool
     */
    public function IsAdmin()
    {
        return Permission::check("ADMIN");
    }

    /**
     * Check the permission to make sure the current user has a mandrill
     *
     * @return bool
     */
    public function canView($member = null)
    {
        $mailer = Injector::inst()->get(Mailer::class);
        if (get_class($mailer) != MandrillMailer::class) {
            return false;
        }
        return Permission::check("CMS_ACCESS_Mandrill", "any", $member);
    }

    /**
     * Check if webhook is installed
     *
     * @return array
     */
    public function WebhookInstalled()
    {
        $mandrill = $this->getMandrill();

        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = 'webooks';
            $cache_result = $cache->get($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $list = unserialize($cache_result);
        } else {
            try {
                $list = $mandrill->webhooks->getList();
                if ($cache_enabled) {
                    $cache->set($cache_key,serialize($list), 60 * self::WEBHOOK_CACHE_MINUTES);
                }
            } catch (Exception $ex) {
                $list = array();
                SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
            }
        }
        if (empty($list)) {
            return false;
        }
        $url = $this->WebhookUrl();
        foreach ($list as $el) {
            if ($el['url'] === $url) {
                return $el;
            }
        }
        return false;
    }

    /**
     * Hook details for template
     * @return \ArrayData
     */
    public function WebhookDetails()
    {
        $el = $this->WebhookInstalled();
        if ($el) {
            return new ArrayData($el);
        }
    }

    /**
     * @return string
     */
    public function WebhookUrl()
    {
        return Director::absoluteURL('/mandrill/incoming');
    }

    /**
     *
     * @return bool
     */
    public function CanConfigureWebhooks()
    {
        return Permission::check('ADMIN') || Director::isDev();
    }

    /**
     * Install hook form
     *
     * @return \Form
     */
    public function InstallHookForm()
    {
        $fields = new FieldList();
        $fields->push(new LiteralField('Info', '<div class="message info">' . _t('MandrillAdmin.HookNotInstalled', 'Hook is not installed. Url of the webhook is: {url}. This url must be publicly visible to be used as a hook.', array('url' => $this->WebhookUrl())) . '</div>'));
        $actions = new FieldList();
        $actions->push(new FormAction('doInstallHook', _t('Mandrill.DOINSTALL', 'Install hook')));
        $form = new Form($this, 'InstallHookForm', $fields, $actions);
        return $form;
    }

    public function doInstallHook($data, Form $form)
    {
        $mandrill = $this->getMandrill();

        $url = $this->WebhookUrl();
        $description = SiteConfig::current_site_config()->Title;

        try {
            $mandrill->webhooks->add($url, $description);
            $this->getCache()->clean('matchingTag', array(self::WEBHOOK_TAG));
        } catch (Exception $ex) {
            SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
        }

        return $this->redirectBack();
    }

    /**
     * Uninstall hook form
     *
     * @return \Form
     */
    public function UninstallHookForm()
    {
        $fields = new FieldList();
        $fields->push(new LiteralField('Info', '<div class="message info">' . _t('MandrillAdmin.HookInstalled', 'Hook is installed. Url of the webhook is: {url}.', array('url' => $this->WebhookUrl())) . '</div>'));
        $actions = new FieldList();
        $actions->push(new FormAction('doUninstallHook', _t('Mandrill.DOUNINSTALL', 'Uninstall hook')));
        $form = new Form($this, 'InstallHookForm', $fields, $actions);
        return $form;
    }

    public function doUninstallHook($data, Form $form)
    {
        $mandrill = $this->getMandrill();

        $url = $this->WebhookUrl();
        $description = SiteConfig::current_site_config()->Title;

        try {
            $el = $this->WebhookInstalled();
            $mandrill->webhooks->delete($el['id']);
            $this->getCache()->clean('matchingTag', array(self::WEBHOOK_TAG));
        } catch (Exception $ex) {
            SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
        }

        return $this->redirectBack();
    }
}
