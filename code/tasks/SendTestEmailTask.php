<?php

use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\Debug;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Control\Email\Email;
use SilverStripe\Security\Member;
use SilverStripe\Dev\BuildTask;

/**
 * A simple task to test if your emails are sending properly
 * @author lekoala
 */
class SendTestEmailTask extends BuildTask
{

    protected $title = "Send Test Email Task";
    protected $description = 'Send a sample email to admin or to ?email=';

    public function run($request)
    {
        $config = SiteConfig::current_site_config();

        $default = Email::config()->admin_email;
        $default_config = $config->DefaultFromEmail;
        $member = Security::getCurrentUser();
        $to = $request->getVar('email');
        $template = $request->getVar('template');
        $disabled = $request->getVar('disabled');

        if ($disabled) {
            MandrillMailer::setSendingDisabled();
        }

        if ($default) {
            echo "Default email address is $default<br/>";
        } else {
            echo "<div style='color:red'>Default email is not set. You should define one!</div>";
        }
        if ($default_config) {
            echo "Default email set in siteconfig is $default_config<br/>";
        }
        echo "The email will be sent to admin email, current member or an email passed in the url, like ?email=myemail@test.com<br/>";
        echo "A default email is used by default. You can use a preset template by setting ?template=mytemplate<br/>";
        echo "To prevent email from being sent, you can pass ?disabled=1<br/>";
        echo '<hr/>';

        echo Config::inst()->get(MandrillMailer::class, 'mandrill_api_key') . '<br>';

        if (!$default && $default_config) {
            $default = $default_config;
        }

        if (!$member && !$to) {
            if (!$default) {
                echo 'There are no recipient defined!';
                exit();
            } else {
                $to = $default;
            }
        }
//
//        if ($template) {
//            $emailTemplate = EmailTemplate::getByCode($template);
//            $email = $emailTemplate->getEmail();
//            $email->setSubject('Template ' . $template . ' from ' . $config->Title);
//            $email->setSampleRequiredObjects();
//        } else {
            $email = new MandrillEmail();
            $email->setSampleContent();
            $email->setSubject('Sample email from ' . $config->Title);
//        }

        if (!$to) {
            $email->setToMember($member);
        } else {
            /** @var Member $member */
            $member = Member::get()->filter('Email', $to)->first();
            if ($member) {
                $email->setToMember($member);
            } else {
                $email->setTo($to);
            }
        }

        Debug::dump($to);

        echo 'Sending to ' . implode(', ', array_keys($email->getTo())) . '<br/>';
        echo 'Using theme : ' . $email->getTheme() . '<br/>';
        echo '<hr/>';

        $res = $email->send();


        // Success!
        if ($res && is_array($res)) {
            echo '<div style="color:green">Successfully sent your email</div>';
            echo 'Recipient : ' . $res[0] . '<br/>';
            echo 'Additional headers : <br/>';
            foreach ($res[3] as $k => $v) {
                echo "$k : $v" . '<br/>';
            }
            echo 'Content : ' . $res[2];
        }
        // Failed!
        else {
            echo '<div style="color:red">Failed to send email</div>';

            Debug::dump(MandrillMailer::getInstance()->getLastError());
            echo 'Error is : ' . MandrillMailer::getInstance()->getLastError();
        }
    }
}
