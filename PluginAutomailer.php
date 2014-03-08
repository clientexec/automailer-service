<?php

require_once 'library/CE/NE_MailGateway.php';
include_once 'modules/clients/models/Client_EventLog.php';
require_once 'modules/admin/models/ServicePlugin.php';
require_once 'modules/support/models/AutoresponderTemplateGateway.php';
require_once 'modules/clients/models/UserPackageGateway.php';
require_once 'modules/admin/models/Package.php';
include_once 'modules/admin/models/NotificationGateway.php';
include_once 'modules/admin/models/UserNotificationGateway.php';

/**
* @package Plugins
*/
class PluginAutomailer extends ServicePlugin
{
    public $hasPendingItems = false;

    function getVariables()
    {
        $variables = array(
            /*T*/'Plugin Name'/*/T*/   => array(
                'type'          => 'hidden',
                'description'   => /*T*/''/*/T*/,
                'value'         => /*T*/'Auto Mailer'/*/T*/,
            ),
            /*T*/'Enabled'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'When enabled, email customers a set number of days before/after a given event, defined on <a href="index.php?fuse=admin&controller=notifications&view=adminviewnotifications"><b><u>Accounts&nbsp;>&nbsp;Notifications</u></b></a>.<br><b>NOTE:</b> Only run once per day to avoid duplicate E-mails.'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'Summary E-mail'/*/T*/     => array(
                'type'          => 'textarea',
                'description'   => /*T*/'E-mail addresses to which a summary of each service run will be sent.  (Leave blank if you do not wish to receive a summary)'/*/T*/,
                'value'         => '',
            ),
            /*T*/'Summary E-mail Subject'/*/T*/     => array(
                'type'          => 'text',
                'description'   => /*T*/'E-mail subject for the summary notification.'/*/T*/,
                'value'         => 'Auto Mailer Summary',
            ),
            /*T*/'Run schedule - Minute'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '0',
                'helpid'        => '8',
            ),
            /*T*/'Run schedule - Hour'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'Run schedule - Day'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Month'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Day of the week'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number in range 0-6 (0 is Sunday) or a 3 letter shortcut (e.g. sun)'/*/T*/,
                'value'         => '*',
            ),
        );

        return $variables;
    }

    function execute()
    {
        $messages = array();
        $numCustomers = 0;
        $mailGateway = new NE_MailGateway();
        $UserNotificationGateway = new UserNotificationGateway();

        //delete user notifications older than 1 week.
        //$UserNotificationGateway->user = $this->user;
        //$UserNotificationGateway->deleteExpiredUserNotifications(60*60*24*7);   //60 seconds * 60 minutes * 24 hours * 7 days

        // Summary Variables
        $summaryNames = array();
        $summaryErrors = array();

        // Required Services
        $requiredServices = array();

        /*
        Get the params:
          - Name of the rule
          - Email template (from custom emails. will display the template name, but will use the template id)
          - Rules: a serialized array withd fields and params. Array structure is:
                array(
                    'match'          => 'all',                                      // values: 'all', 'any'

                    'overrideOptOut' => '1',                                        // values: '1' = YES
                                                                                               '0' = NO

                    'rules'          => array(

                        array(                                                      // One array per field rule

                          'fieldtype' => 'Field Classification',                    // values: 'System',
                                                                                    //         'User',
                                                                                    //         'User Custom Field',
                                                                                    //         'Package',
                                                                                    //         'Package Custom Field'

                          'fieldname' => 'System Field Name, or Custom Field ID',   // values by fieldtype:
                                                                                    //     System
                                                                                    //         'After Account Pending',
                                                                                    //         'After Account Activated',
                                                                                    //         'After Account Canceled',
                                                                                    //         'After Package Activated',
                                                                                    //         'After Package Canceled',
                                                                                    //         'Before Domain Expires',
                                                                                    //         'Before Hosting Package Due Date',
                                                                                    //         'Before Domain Package Due Date',
                                                                                    //         'Before SSL Package Due Date',
                                                                                    //         'Before General Package Due Date'
                                                                                    //
                                                                                    //     User
                                                                                    //         * User Field name          (`users`.FIELD_NAME)
                                                                                    //
                                                                                    //     User Custom Field
                                                                                    //         * User Custom Field id     (`customuserfields`.`id`)
                                                                                    //
                                                                                    //     Package
                                                                                    //         * Package Field name       (`domains`.FIELD_NAME)
                                                                                    //
                                                                                    //     Package Custom Field
                                                                                    //         * Package Custom Field id  (`customField`.`id`)

                          'operator'  => '<=',                                      // values: '<', '<=', '>', '>=', '=', '!='

                          'value'     => '5',
                          'comment'   => 'days'
                        ),
                        array(                                                      // another field rule array for the example.
                          'fieldtype' => 'Field Classification 2',
                          'fieldname' => 'Field Name 2',
                          'operator'  => '=',
                          'value'     => '3',
                          'comment'   => 'days'
                        )
                    )
                )
          - Enabled: 1 = YES, 0 = NO

          For example:
            Salutation                                  $AutomailerRule->getName()
            37 (Are you enjoying our application?)      $AutomailerRule->getTemplateID()
            serialized array with fields and params     $AutomailerRule->getRules()
            1                                           $AutomailerRule->getEnabled()
        */
        $gateway = new NotificationGateway();
        $AutomailerRules = $gateway->getNotifications();

        //Get the customers for each case:
        while ($AutomailerRule = $AutomailerRules->fetch()) {
            if($AutomailerRule->getEnabled() == 1){
                $Rules = $AutomailerRule->getRules();

                if($Rules == ''){
                    array_unshift($messages, $this->user->lang('%s customer(s) were notified.', $numCustomers));
                    return $messages;
                }
                $Rules = unserialize($Rules);
                if(!is_array($Rules)){
                    array_unshift($messages, $this->user->lang('%s customer(s) were notified.', $numCustomers));
                    return $messages;
                }

                $result = $this->getResults($AutomailerRule);

                if($result === false){
                    continue;
                }

                if(count($Rules['rules']) == 1 && $Rules['rules'][0]['fieldtype'] == 'System' && $Rules['rules'][0]['fieldname'] == 'Before Domain Expires'){
                    // Requires "Domain Updater" Service
                    $requiredServices[] = 'domainupdater';
                }

                // If find customers:
                if($result->getNumRows()){
                    // - Setup the customer email template
                    $templategateway = new AutoresponderTemplateGateway();
                    $template = $templategateway->getAutoresponder($AutomailerRule->getTemplateID());

                    if($template->getId() != $AutomailerRule->getTemplateID()){
                        $summaryErrors[] = $AutomailerRule->getTemplateID();
                    }else{
                        $strEmailArrT = $template->getContents(true);
                        $strSubjectEmailT = $template->getSubject();
                        $strNameEmailT = $template->getName();

                        // - For each customer:
                        while($row = $result->fetch()){
                            //ignore if the notification was already sent
                            if(!$UserNotificationGateway->existUserNotification(((isset($row['package_id']))? 'package' : 'user'), ((isset($row['package_id']))? $row['package_id'] : $row['customer_id']), $AutomailerRule->getId(), $AutomailerRule->isSystem())){
                                // * Instantiate the user
                                $user = new User($row['customer_id']);

                                // * Create a copy of the email template
                                $strEmailArr     = $strEmailArrT;
                                $strSubjectEmail = $strSubjectEmailT;

                                // * Parse a copy of the email template and the email subject template
                                if(isset($row['package_id'])){
                                    $userPackage = new UserPackage((int)$row['package_id']);
                                    $package = new Package($userPackage->Plan);

                                    $gateway = new UserPackageGateway($this->user);

                                    $strSubjectEmail = $gateway->_replaceTags1($strSubjectEmail,$user,$package);
                                    $strEmailArr = array(
                                        'HTML'      => $gateway->_replaceTags1($strEmailArr['HTML'], $user, $package),
                                        'plainText' => $gateway->_replaceTags1($strEmailArr['plainText'],$user,$package)
                                    );

                                    $gateway->_replaceTagsByType($userPackage,$user,$strEmailArr, $strSubjectEmail);


                                    $additionalEmailTags = array(
                                        "[PACKAGEGROUPNAME]" => $package->productGroup->fields['name'],
                                        "[PACKAGEID]"        => $row['package_id'],
                                        "[NEXTDUEDATE]"      => date($this->settings->get('Date Format'), $dateTimeStamp),
                                        "[BILLINGEMAIL]"     => $this->settings->get("Billing E-mail")
                                    );
                                    $strSubjectEmail = str_replace(array_keys($additionalEmailTags), $additionalEmailTags, $strSubjectEmail);
                                    $strEmailArr = array(
                                        'HTML'      => str_replace(array_keys($additionalEmailTags), $additionalEmailTags, $strEmailArr['HTML']),
                                        'plainText' => str_replace(array_keys($additionalEmailTags), $additionalEmailTags, $strEmailArr['plainText'])
                                    );
                                }else{
                                    $gateway = new UserPackageGateway($this->user);

                                    $strSubjectEmail = $gateway->_replaceTags1($strSubjectEmail,$user);
                                    $strEmailArr = array(
                                        'HTML'      => $gateway->_replaceTags1($strEmailArr['HTML'], $user),
                                        'plainText' => $gateway->_replaceTags1($strEmailArr['plainText'],$user)
                                    );
                                }

                                // * Send a parsed copy of the email template to the customer
                                $mailerResult = $mailGateway->mailMessage(
                                    $strEmailArr,
                                    $this->settings->get('Support E-mail'),
                                    $this->settings->get('Company Name'),
                                    $row['customer_id'],
                                    "",
                                    $strSubjectEmail,
                                    3,
                                    0,
                                    'notifications',
                                    '',
                                    '',
                                    $user->isHTMLMails()? MAILGATEWAY_CONTENTTYPE_HTML : MAILGATEWAY_CONTENTTYPE_PLAINTEXT
                                );

                                if (!($mailerResult instanceof CE_Error)) {
                                    // log the email sent
                                    $clientsEventLog = Client_EventLog::newInstance(false, $row['customer_id'], $row['customer_id'], CLIENT_EVENTLOG_SENTNOTIFICATIONEMAIL, $this->user->getId());
                                    $clientsEventLog->setEmailSent($strSubjectEmail, $strEmailArr['HTML']);
                                    $clientsEventLog->save();

                                    //track the notification by adding it to the user_notifications table
                                    $userNotification = new UserNotification();
                                    $userNotification->setObjectType(((isset($row['package_id']))? 'package' : 'user'));
                                    $userNotification->setObjectID(((isset($row['package_id']))? $row['package_id'] : $row['customer_id']));
                                    $userNotification->setRuleID($AutomailerRule->getId());
                                    $userNotification->setDate(date("Y-m-d H:i:s"));
                                    $userNotification->save();
                                }

                                // * Add Customer to summary
                                $summaryNames[$AutomailerRule->getName()][] = $user->getFullName().((isset($row['package_id']))? ', package: '.$row['package_id'] : '');
                                $numCustomers++;
                            }
                        }
                    }
                }
            }
        }

        if($this->settings->get('plugin_automailer_Summary E-mail') != ""){
            $summaryEmail = '';
            if(count($requiredServices) > 0){
                $summaryEmailRequirementsIssues = array();
                $summaryEmailRequirementsTime = array();
                foreach($requiredServices as $requiredService){
                    // Get the Service name
                    $requiredServiceName = $this->settings->get('plugin_'.$requiredService.'_Plugin Name');
                    if(!$requiredServiceName){
                        $requiredServiceName = $requiredService;
                    }

                    // Verify if the Service is enabled
                    if(!$this->settings->get('plugin_'.$requiredService.'_Enabled')){
                        $summaryEmailRequirementsIssues[] = $this->user->lang("The service %s is not enabled", $requiredServiceName);
                    }else{
                        // Verify the last time the Service ran
                        $requiredServiceInfo = $this->settings->get('service_'.$requiredService.'_info');
                        if(!$requiredServiceInfo){
                            $summaryEmailRequirementsIssues[] = $this->user->lang("The service %s does not have information about its last run.", $requiredServiceName);
                        }else{
                            $requiredServiceInfo = unserialize($requiredServiceInfo);
                            if(!is_array($requiredServiceInfo) || !isset($requiredServiceInfo['time'])){
                                $summaryEmailRequirementsIssues[] = $this->user->lang("The information of the service %s about its last run, seems to be corrupted.", $requiredServiceName);
                            }else{
                                $summaryEmailRequirementsTime[$requiredServiceName] = $requiredServiceInfo['time'];
                            }
                        }
                    }
                }
                if (count($summaryEmailRequirementsIssues) > 0) {
                    $summaryEmail .= $this->user->lang("Auto Mailer has detected issues with some of the required Services for the Events selected. Please take a look").":\n";

                    foreach($summaryEmailRequirementsIssues as $summaryEmailRequirementsIssue){
                        $summaryEmail .= " - ".$summaryEmailRequirementsIssue."\n";
                    }
                    $summaryEmail .= "\n";
                }
                if (count($summaryEmailRequirementsTime) > 0) {
                    $summaryEmail .= $this->user->lang("Last execution of the required Services for the Events selected, were").":\n";

                    foreach($summaryEmailRequirementsTime as $summaryEmailRequirementName => $summaryEmailRequirementTime){
                        $summaryEmail .= " - ".$summaryEmailRequirementName.". Executed on: ".$summaryEmailRequirementTime."\n";
                    }
                    $summaryEmail .= "\n";
                }
            }


            if (count($summaryErrors) > 0) {
                $summaryEmail .= $this->user->lang("Auto Mailer has not been able to find the Email Templates with the following ids. Please take a look").":\n";

                foreach($summaryErrors as $summaryError){
                    $summaryEmail .= " - ".$summaryError."\n";
                }
                $summaryEmail .= "\n";
            }

            if(count($summaryNames) > 0){
                $summaryEmail .= $this->user->lang("Auto Mailer has emailed the following events to the following customers").":\n";

                foreach($summaryNames as $NotificationName => $summaryCustomers){
                    $summaryEmail .= "\n".$NotificationName.":\n";

                    foreach($summaryCustomers as $summaryCustomer){
                        $summaryEmail .= " - ".$summaryCustomer."\n";
                    }
                }
            }

            if($summaryEmail != ''){
                $destinataries = explode("\r\n", $this->settings->get('plugin_automailer_Summary E-mail'));

                foreach($destinataries as $destinatary){
                    $mailGateway->mailMessageEmail(
                        $summaryEmail,
                        $this->settings->get('Support E-mail'),
                        $this->settings->get('Company Name'),
                        $destinatary,
                        "",
                        $this->settings->get('plugin_automailer_Summary E-mail Subject')
                    );
                }
            }
        }

        array_unshift($messages, $this->user->lang('%s customer(s) were notified.', $numCustomers));
        return $messages;
    }

    function getResults($AutomailerRule)
    {
        $Rules = $AutomailerRule->getRules();
        $Rules = unserialize($Rules);
        $Match = $Rules['match'];

        //IGNORE IF CUSTOMER DO NOT WANT EMAILS, UNLESS THE NOTIFICATION SAYS TO SEND TO ALL.
        $overrideOptOut = isset($Rules['overrideOptOut'])? $Rules['overrideOptOut'] : '1';
        $excludeJoin = '';
        $excludeWhere = '';
        if(!$overrideOptOut){
            $query = "SELECT id "
                    ."FROM customuserfields "
                    ."WHERE type = ?";
            $result = $this->db->query($query, TYPE_ALLOW_EMAIL);
            $row = $result->fetch();
            
            $excludeJoin = "JOIN `user_customuserfields` ucufex "
                          ."ON u.`id` = ucufex.`userid` ";
            $excludeWhere = "AND ucufex.`customid` = ".$row['id']." "
                           ."AND ucufex.`value` = 1 ";
        }

        $Rules = $Rules['rules'];

        if($AutomailerRule->isSystem()){
            //IT IS A PREDEFINED RULE

            switch($Rules[0]['fieldname']){
                // Before Dates
                case 'Before Domain Expires':
                case 'Before Hosting Package Due Date':
                case 'Before Domain Package Due Date':
                case 'Before SSL Package Due Date':
                case 'Before General Package Due Date':
                    $dateTimeStamp = mktime(0, 0, 0, date("m"), date("d") + $Rules[0]['value'], date("Y"));
                    break;

                // After Dates
                case 'After Account Pending':
                case 'After Account Activated':
                case 'After Account Canceled':
                case 'After Package Activated':
                case 'After Package Canceled':
                default:
                    $dateTimeStamp = mktime(0, 0, 0, date("m"), date("d") - $Rules[0]['value'], date("Y"));
                    break;
            }

            switch($Rules[0]['fieldname']){
                case 'After Account Pending':
                    $query = "SELECT u.`id` AS customer_id "
                            ."FROM `users` u "
                            .$excludeJoin
                            ."WHERE u.`groupid` = 1 "
                            ."AND u.`status` IN (".USER_STATUS_PENDING.") "
                            ."AND (UNIX_TIMESTAMP(u.`dateActivated`) >= ?) "
                            ."AND (UNIX_TIMESTAMP(u.`dateActivated`) < ?) "
                            .$excludeWhere;
                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                case 'After Account Activated':
                    $query = "SELECT u.`id` AS customer_id "
                            ."FROM `users` u "
                            ."JOIN `user_customuserfields` ucuf "
                            ."ON u.`id` = ucuf.`userid` "
                            .$excludeJoin
                            ."WHERE u.`groupid` = 1 "
                            ."AND u.`status` IN (".USER_STATUS_ACTIVE.") "
                            ."AND ucuf.`customid` IN ( "
                            ."SELECT cuf.`id` "
                            ."FROM `customuserfields` cuf "
                            ."WHERE cuf.`name` = 'Last Status Date' "
                            ."AND cuf.`type` = 52) "
                            ."AND (UNIX_TIMESTAMP(ucuf.`value`) >= ?) "
                            ."AND (UNIX_TIMESTAMP(ucuf.`value`) < ?) "
                            .$excludeWhere;
                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                case 'After Account Canceled':  // Includes Canceled and Inactive Users
                    $query = "SELECT u.`id` AS customer_id "
                            ."FROM `users` u "
                            ."JOIN `user_customuserfields` ucuf "
                            ."ON u.`id` = ucuf.`userid` "
                            .$excludeJoin
                            ."WHERE u.`groupid` = 1 "
                            ."AND u.`status` IN (".USER_STATUS_INACTIVE.", ".USER_STATUS_CANCELLED.") "
                            ."AND ucuf.`customid` IN ( "
                            ."SELECT cuf.`id` "
                            ."FROM `customuserfields` cuf "
                            ."WHERE cuf.`name` = 'Last Status Date' "
                            ."AND cuf.`type` = 52) "
                            ."AND (UNIX_TIMESTAMP(ucuf.`value`) >= ?) "
                            ."AND (UNIX_TIMESTAMP(ucuf.`value`) < ?) "
                            .$excludeWhere;
                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                case 'After Package Activated':
                    $query = "SELECT DISTINCT u.`id` AS customer_id, d.`id` AS package_id "
                            ."FROM `users` u "
                            ."JOIN `domains` d "
                            ."ON u.`id` = d.`CustomerID` "
                            ."JOIN `object_customField` ocf "
                            ."ON d.`id` = ocf.`objectid` "
                            .$excludeJoin
                            ."WHERE d.`status` IN (".PACKAGE_STATUS_ACTIVE.") "
                            ."AND ocf.`customFieldId` IN ( "
                            ."SELECT cf.`id` "
                            ."FROM `customField` cf "
                            ."WHERE cf.`name` = 'Last Status Date' "
                            ."AND cf.`groupId` = 2) "
                            ."AND (UNIX_TIMESTAMP(ocf.`value`) >= ?) "
                            ."AND (UNIX_TIMESTAMP(ocf.`value`) < ?) "
                            .$excludeWhere;

                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                case 'After Package Canceled':  // Includes Canceled, Suspended, and Expired Packages
                    $query = "SELECT DISTINCT u.`id` AS customer_id, d.`id` AS package_id "
                            ."FROM `users` u "
                            ."JOIN `domains` d "
                            ."ON u.`id` = d.`CustomerID` "
                            ."JOIN `object_customField` ocf "
                            ."ON d.`id` = ocf.`objectid` "
                            .$excludeJoin
                            ."WHERE d.`status` IN (".PACKAGE_STATUS_SUSPENDED.", ".PACKAGE_STATUS_CANCELLED.", ".PACKAGE_STATUS_EXPIRED.") "
                            ."AND ocf.`customFieldId` IN ( "
                            ."SELECT cf.`id` "
                            ."FROM `customField` cf "
                            ."WHERE cf.`name` = 'Last Status Date' "
                            ."AND cf.`groupId` = 2) "
                            ."AND (UNIX_TIMESTAMP(ocf.`value`) >= ?) "
                            ."AND (UNIX_TIMESTAMP(ocf.`value`) < ?) "
                            .$excludeWhere;
                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                case 'Before Domain Expires':  // Includes Active and Pending Cancellation Packages
                    // Query based on the custom field "Expiration Date".  The field value is timestamp type.
                    $query = "SELECT DISTINCT u.`id` AS customer_id, d.`id` AS package_id "
                            ."FROM `users` u "
                            ."JOIN `domains` d "
                            ."ON u.`id` = d.`CustomerID` "
                            ."JOIN `object_customField` ocf "
                            ."ON d.`id` = ocf.`objectid` "
                            .$excludeJoin
                            ."WHERE d.`status` IN (".PACKAGE_STATUS_ACTIVE.", ".PACKAGE_STATUS_PENDINGCANCELLATION.") "
                            ."AND d.`Plan` IN ( "
                            ."SELECT pa.`id` "
                            ."FROM `package` pa "
                            ."WHERE pa.`planid` IN ( "
                            ."SELECT pr.`id` "
                            ."FROM `promotion` pr "
                            ."WHERE pr.`type` = 3)) "
                            ."AND ocf.`customFieldId` IN ( "
                            ."SELECT cf.`id` "
                            ."FROM `customField` cf "
                            ."WHERE cf.`name` = 'Expiration Date' "
                            ."AND cf.`groupId` = 2 "
                            ."AND cf.`subGroupId` = 3) "
                            ."AND ocf.`value` >= ? "
                            ."AND ocf.`value` < ? "
                            .$excludeWhere;
                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                case 'Before Hosting Package Due Date':
                case 'Before Domain Package Due Date':
                case 'Before SSL Package Due Date':
                case 'Before General Package Due Date':
                    $packageTypeId = PACKAGE_TYPE_GENERAL;
                    switch($Rules[0]['fieldname']){
                        case 'Before Hosting Package Due Date':
                            $packageTypeId = PACKAGE_TYPE_HOSTING;
                            break;
                        case 'Before Domain Package Due Date':
                            $packageTypeId = PACKAGE_TYPE_DOMAIN;
                            break;
                        case 'Before SSL Package Due Date':
                            $packageTypeId = PACKAGE_TYPE_SSL;
                            break;
                        case 'Before General Package Due Date':
                            $packageTypeId = PACKAGE_TYPE_GENERAL;
                            break;
                    }

                    // Query based on the "nextbilldate" field of the "recurringfee" table.
                    $query = "SELECT DISTINCT u.`id` AS customer_id, d.`id` AS package_id "
                            ."FROM `users` u "
                            ."JOIN `domains` d "
                            ."ON u.`id` = d.`CustomerID` "
                            ."JOIN `recurringfee` rf "
                            ."ON d.`id` = rf.`appliestoid` "
                            ."AND rf.`billingtypeid` = -1 "
                            ."AND rf.`recurring` = 1 "
                            ."AND rf.paymentterm != 0 "
                            .$excludeJoin
                            ."WHERE d.`status` IN (".PACKAGE_STATUS_ACTIVE.", ".PACKAGE_STATUS_PENDINGCANCELLATION.") "
                            ."AND d.`Plan` IN ( "
                            ."SELECT pa.`id` "
                            ."FROM `package` pa "
                            ."WHERE pa.`planid` IN ( "
                            ."SELECT pr.`id` "
                            ."FROM `promotion` pr "
                            ."WHERE pr.`type` = $packageTypeId)) "
                            ."AND (UNIX_TIMESTAMP(rf.`nextbilldate`) >= ?) "
                            ."AND (UNIX_TIMESTAMP(rf.`nextbilldate`) < ?) "
                            .$excludeWhere;
                    $result = $this->db->query($query, $dateTimeStamp, $dateTimeStamp + 86400);
                    break;
                default:
                    $result = false;
                    break;
            }
        }else{
            $joinFilters = "";
            $whereFiltersArray = array();
            $hasPackage = 0;
            $joinIndex = 0;   // To tag the join tables with different names and avoid issues
            $parameters = array();
            foreach($Rules as $Rule){
                if($Rule['fieldtype'] == 'User'){
                    $whereFiltersArray[] = "( u.`".$Rule['fieldname']."` ".$Rule['operator']." ? ) ";
                    $parameters[] = $Rule['value'];
                }elseif($Rule['fieldtype'] == 'User Custom Field'){
                    $joinFilters .= " JOIN `user_customuserfields` ucuf".$joinIndex." ON u.`id` = ucuf".$joinIndex.".`userid` ";
                    $whereFiltersArray[] = "( ucuf".$joinIndex.".`customid` = ".$Rule['fieldname']." AND ucuf".$joinIndex.".`value` ".$Rule['operator']." ? ) ";
                    $parameters[] = $Rule['value'];
                }elseif($Rule['fieldtype'] == 'Package'){
                    $hasPackage = 1;
                    $whereFiltersArray[] = "( d.`".$Rule['fieldname']."` ".$Rule['operator']." ? ) ";
                    $parameters[] = $Rule['value'];
                }elseif($Rule['fieldtype'] == 'Package Custom Field'){
                    $hasPackage = 1;
                    $joinFilters .= " JOIN `object_customField` ocf".$joinIndex." ON d.`id` = ocf".$joinIndex.".`objectid` ";
                    $whereFiltersArray[] = "( ocf".$joinIndex.".`customFieldId` = ".$Rule['fieldname']." AND ocf".$joinIndex.".`value` ".$Rule['operator']." ? ) ";
                    $parameters[] = $Rule['value'];
                }
                $joinIndex++;
            }

            $whereFilters = "";
            if(count($whereFiltersArray) > 0){
                if($Match === 'all'){
                    $whereFilters = " AND (".implode(" AND ", $whereFiltersArray).") ";
                }elseif($Match === 'any'){
                    $whereFilters = " AND (".implode(" OR ", $whereFiltersArray).") ";
                }
            }

            $selectPackageID = "";
            $joinDomains = "";
            if($hasPackage){
                $selectPackageID = " , d.`id` AS package_id ";
                $joinDomains = " JOIN `domains` d ON u.`id` = d.`CustomerID` ";
            }

            $query = "SELECT DISTINCT u.`id` AS customer_id "
                    .$selectPackageID
                    ." FROM `users` u "
                    .$joinDomains
                    .$joinFilters
                    .$excludeJoin
                    ." WHERE u.`groupid` = 1 "
                    .$whereFilters
                    .$excludeWhere;
            try{
                $result = $this->db->query($query, $parameters);
            }catch(Exception $ex){
                return false;
            }
        }

        return $result;
    }

    function getAppliesTo($AutomailerRule)
    {
        $appliesToArray = array(
            'apply'  => array(
                'users'    => array(),
                'packages' => array()
            ),
            'ignore' => array(
                'users'    => array(),
                'packages' => array()
            )
        );
        $UserNotificationGateway = new UserNotificationGateway();

        $Rules = $AutomailerRule->getRules();

        if($Rules == ''){
            return $appliesToArray;
        }
        $Rules = unserialize($Rules);
        if(!is_array($Rules)){
            return $appliesToArray;
        }

        $result = $this->getResults($AutomailerRule);

        if($result === false){
            return $appliesToArray;
        }

        // If find customers:
        if($result->getNumRows()){
            // - For each customer:
            while($row = $result->fetch()){
                //ignore if the notification was already sent
                if(!$UserNotificationGateway->existUserNotification(((isset($row['package_id']))? 'package' : 'user'), ((isset($row['package_id']))? $row['package_id'] : $row['customer_id']), $AutomailerRule->getId(), $AutomailerRule->isSystem())){
                    if(isset($row['package_id'])){
                        $appliesToArray['apply']['packages'][] = $row['package_id'];
                    }else{
                        $appliesToArray['apply']['users'][] = $row['customer_id'];
                    }
                }else{
                    if(isset($row['package_id'])){
                        $appliesToArray['ignore']['packages'][] = $row['package_id'];
                    }else{
                        $appliesToArray['ignore']['users'][] = $row['customer_id'];
                    }
                }
            }
        }
        return $appliesToArray;
    }
}
?>