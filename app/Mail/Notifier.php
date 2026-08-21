<?php

namespace ClientVerification\Mail;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Sends notifications via WHMCS native email system (SendEmail / SendAdminEmail) and Admin Alerts.
 * Fail-safe architecture with fallback rendering, admin username resolution, and debug diagnostics.
 */
class Notifier
{
    /**
     * Resolve an active WHMCS Admin username to authenticate localAPI calls from client area, webhooks, or cron.
     */
    public static function getAdminUsername(): string
    {
        $adminUser = '';
        if (!empty($_SESSION['adminid'])) {
            try {
                $adminUser = Capsule::table('tbladmins')->where('id', (int) $_SESSION['adminid'])->value('username') ?: '';
            } catch (\Throwable $e) {}
        }
        if (empty($adminUser)) {
            try {
                $adminUser = Capsule::table('tbladmins')->where('disabled', 0)->orderBy('id', 'asc')->value('username') ?: '';
            } catch (\Throwable $e) {}
        }
        if (empty($adminUser)) {
            try {
                $adminUser = Capsule::table('tbladmins')->orderBy('id', 'asc')->value('username') ?: 'admin';
            } catch (\Throwable $e) {}
        }
        return (string) ($adminUser ?: 'admin');
    }

    /**
     * Get WHMCS Company Name
     */
    public static function getCompanyName(): string
    {
        $name = '';
        if (class_exists('\\WHMCS\\Config\\Setting')) {
            try {
                $name = (string) \WHMCS\Config\Setting::getValue('CompanyName');
            } catch (\Throwable $e) {}
        }
        if (!$name) {
            try {
                $name = (string) (Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value') ?: 'Hostnibo');
            } catch (\Throwable $e) {}
        }
        return $name ?: 'Identity Verification';
    }

    /**
     * Get WHMCS System From Email
     */
    public static function getSystemFromEmail(): string
    {
        $email = '';
        if (class_exists('\\WHMCS\\Config\\Setting')) {
            try {
                $email = (string) \WHMCS\Config\Setting::getValue('SystemEmailsFromEmail');
            } catch (\Throwable $e) {}
        }
        if (!$email) {
            try {
                $email = (string) (Capsule::table('tblconfiguration')->where('setting', 'SystemEmailsFromEmail')->value('value') ?: '');
            } catch (\Throwable $e) {}
        }
        if (!$email && !empty($_SERVER['HTTP_HOST'])) {
            $email = 'noreply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST']);
        }
        return $email ?: 'noreply@localhost';
    }

    /**
     * Send client email via WHMCS native templates with multi-tier fallback.
     *
     * @param string $template WHMCS email template name
     * @param int    $clientId
     * @param array  $vars
     * @param string $fallbackSubject
     * @param string $fallbackBody
     * @return array Result metadata containing 'success' (bool) and 'log' (array)
     */
    public static function send(string $template, int $clientId, array $vars = [], string $fallbackSubject = '', string $fallbackBody = ''): array
    {
        $debug = [];
        $debug[] = "Initiating email dispatch: Template '{$template}' for Client #{$clientId}";

        if ($clientId <= 0) {
            $debug[] = "Error: Invalid Client ID ({$clientId})";
            return ['success' => false, 'log' => $debug];
        }

        // Ensure default templates exist
        if (function_exists('cv_create_email_templates')) {
            cv_create_email_templates();
        }

        // Fetch client details for merge tags
        $client = null;
        try {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        } catch (\Throwable $e) {
            $debug[] = "Database lookup error for client: " . $e->getMessage();
        }

        $clientName = $client ? trim($client->firstname . ' ' . $client->lastname) : "Client #{$clientId}";
        $clientEmail = $client ? (string) $client->email : '';
        $companyName = self::getCompanyName();
        $verificationUrl = cv_system_url() . '/index.php?m=clientverification';

        // Prepare merged vars
        $defaultVars = [
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'company_name' => $companyName,
            'verification_url' => $verificationUrl,
            'verification_link' => '<a href="' . htmlspecialchars($verificationUrl) . '">' . htmlspecialchars($verificationUrl) . '</a>',
            'sysurl' => cv_system_url(),
        ];
        $mergedVars = array_merge($defaultVars, $vars);
        $adminUser = self::getAdminUsername();

        $debug[] = "Resolved Admin username for localAPI: '{$adminUser}'";
        $debug[] = "Recipient: {$clientName} <{$clientEmail}>";

        // Tier 1: Try localAPI('SendEmail') with template messagename
        if (function_exists('localAPI')) {
            try {
                $apiRes = localAPI('SendEmail', [
                    'messagename' => $template,
                    'id' => $clientId,
                    'customvars' => $mergedVars,
                ], $adminUser);

                $resResult = strtolower((string) ($apiRes['result'] ?? ''));
                $debug[] = "localAPI SendEmail (Template '{$template}'): " . json_encode($apiRes);

                if ($resResult === 'success') {
                    if (function_exists('logActivity')) {
                        logActivity("KYC Verification: Sent email '{$template}' to Client #{$clientId} ({$clientEmail})");
                    }
                    return ['success' => true, 'log' => $debug, 'method' => 'localAPI_template'];
                }
            } catch (\Throwable $e) {
                $debug[] = "localAPI SendEmail exception: " . $e->getMessage();
            }
        }

        // Tier 2: Try localAPI('SendEmail') with customtype => 'general' and customsubject/custommessage
        if (!empty($fallbackSubject) && !empty($fallbackBody) && function_exists('localAPI')) {
            try {
                // Replace merge variables in fallback template
                $subject = str_replace(
                    ['{$client_name}', '{$company_name}', '{$verification_url}', '{$reason}', '{$note}', '{$expiry_date}'],
                    [$clientName, $companyName, $verificationUrl, $mergedVars['reason'] ?? '', $mergedVars['note'] ?? '', $mergedVars['expiry_date'] ?? ''],
                    $fallbackSubject
                );
                $body = str_replace(
                    ['{$client_name}', '{$company_name}', '{$verification_url}', '{$reason}', '{$note}', '{$expiry_date}'],
                    [$clientName, $companyName, $verificationUrl, $mergedVars['reason'] ?? '', $mergedVars['note'] ?? '', $mergedVars['expiry_date'] ?? ''],
                    $fallbackBody
                );

                $apiRes2 = localAPI('SendEmail', [
                    'customtype' => 'general',
                    'customsubject' => $subject,
                    'custommessage' => $body,
                    'id' => $clientId,
                    'customvars' => $mergedVars,
                ], $adminUser);

                $debug[] = "localAPI SendEmail (Custom General fallback): " . json_encode($apiRes2);
                if (strtolower((string) ($apiRes2['result'] ?? '')) === 'success') {
                    if (function_exists('logActivity')) {
                        logActivity("KYC Verification: Sent custom fallback email '{$subject}' to Client #{$clientId}");
                    }
                    return ['success' => true, 'log' => $debug, 'method' => 'localAPI_custom'];
                }
            } catch (\Throwable $e) {
                $debug[] = "localAPI custom SendEmail exception: " . $e->getMessage();
            }
        }

        // Tier 3: Try WHMCS sendMessage() global helper
        if (function_exists('sendMessage')) {
            try {
                sendMessage($template, $clientId, $mergedVars);
                $debug[] = "WHMCS sendMessage() executed for '{$template}'";
                return ['success' => true, 'log' => $debug, 'method' => 'sendMessage'];
            } catch (\Throwable $e) {
                $debug[] = "WHMCS sendMessage() exception: " . $e->getMessage();
            }
        }

        // Tier 4: Direct PHP mail fallback if client has valid email
        if (!empty($clientEmail) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL) && !empty($fallbackSubject) && !empty($fallbackBody)) {
            try {
                $fromEmail = self::getSystemFromEmail();
                $subject = str_replace(['{$client_name}', '{$company_name}', '{$verification_url}'], [$clientName, $companyName, $verificationUrl], $fallbackSubject);
                $body = str_replace(['{$client_name}', '{$company_name}', '{$verification_url}'], [$clientName, $companyName, $verificationUrl], $fallbackBody);

                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: " . self::getCompanyName() . " <" . $fromEmail . ">\r\n";
                $headers .= "Reply-To: " . $fromEmail . "\r\n";

                $mailSent = @mail($clientEmail, $subject, $body, $headers);
                $debug[] = "Direct PHP mail() attempt: " . ($mailSent ? "Success" : "Failed");
                if ($mailSent) {
                    return ['success' => true, 'log' => $debug, 'method' => 'php_mail'];
                }
            } catch (\Throwable $e) {
                $debug[] = "Direct PHP mail exception: " . $e->getMessage();
            }
        }

        $debug[] = "All delivery tiers exhausted without success.";
        return ['success' => false, 'log' => $debug];
    }

    /* =========================================================================
     * CLIENT / USER NOTIFICATIONS
     * ========================================================================= */

    public static function started(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_started', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Identity Verification Started - {$company_name}';
        $body = '<p>Dear {$client_name},</p><p>Your identity verification process has been initiated with <strong>{$company_name}</strong>.</p><p><a href="{$verification_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Complete Verification &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Verification Started', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    public static function approved(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_approved', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Identity Verification Approved - {$company_name}';
        $body = '<p>Dear {$client_name},</p><p>Great news! Your identity verification has been reviewed and <strong style="color: #16a34a;">Approved</strong>.</p><p><a href="{$verification_url}" style="background: #16a34a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">View Verification Status &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Verification Approved', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    public static function rejected(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_rejected', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Identity Verification Update - Action Required';
        $body = '<p>Dear {$client_name},</p><p>Your identity verification submission could not be approved.</p><p><strong>Reason:</strong> {$reason}</p><p><a href="{$verification_url}" style="background: #dc2626; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Submit New Verification &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Verification Rejected', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    public static function reviewRequired(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_under_review', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Identity Verification Received - Under Compliance Review';
        $body = '<p>Dear {$client_name},</p><p>We have received your verification documents. Your submission is currently under review by our compliance team.</p><p><a href="{$verification_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Check Status &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Manual Review Required', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    public static function infoRequired(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_info_requested', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Action Required: Additional Information Needed for Verification';
        $body = '<p>Dear {$client_name},</p><p>Our compliance team requires additional information or clearer documents to finalize your verification.</p><p><strong>Staff Note:</strong> {$note}</p><p><a href="{$verification_url}" style="background: #0284c7; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Upload Requested Information &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Additional Information Required', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    public static function expiring(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_expiring', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Reminder: Your Identity Verification Is Expiring Soon';
        $body = '<p>Dear {$client_name},</p><p>This is a reminder that your annual identity verification will expire on <strong>{$expiry_date}</strong>.</p><p><a href="{$verification_url}" style="background: #d97706; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Renew Verification &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Expiring', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    public static function expired(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_expired', 'yes') !== 'yes') {
            return false;
        }
        $sub = 'Important: Your Identity Verification Has Expired';
        $body = '<p>Dear {$client_name},</p><p>Your identity verification has expired. Please submit updated verification documents to maintain active services.</p><p><a href="{$verification_url}" style="background: #dc2626; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Re-verify Identity Now &raquo;</a></p><p>Regards,<br>{$company_name}</p>';
        $res = self::send('KYC Expired', $clientId, $vars, $sub, $body);
        return $res['success'];
    }

    /* =========================================================================
     * ADMIN NOTIFICATIONS & ROUTING
     * ========================================================================= */

    /**
     * Send email to configured admin notification email(s) or default WHMCS system admins.
     */
    public static function notifyAdmin(string $subject, string $messageHtml): array
    {
        $debug = [];
        $debug[] = "Initiating Admin Notification: '{$subject}'";

        $customEmails = cv_setting('admin_notification_emails', '');
        $emails = array_filter(array_map('trim', explode(',', (string) $customEmails)));
        $adminUser = self::getAdminUsername();
        $fromEmail = self::getSystemFromEmail();
        $companyName = self::getCompanyName();

        $fullHtml = '<!DOCTYPE html><html><body style="font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; line-height: 1.6; color: #1e293b; background: #f8fafc; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="background: #1e40af; padding: 18px 24px; color: #ffffff;">
                    <h3 style="margin: 0; font-size: 18px; color: #ffffff;">' . htmlspecialchars($subject) . '</h3>
                </div>
                <div style="padding: 24px;">' . $messageHtml . '</div>
                <div style="background: #f1f5f9; padding: 12px 24px; font-size: 12px; color: #64748b; text-align: center;">
                    Automated KYC Compliance Notification &bull; ' . htmlspecialchars(cv_system_url()) . '
                </div>
            </div>
        </body></html>';

        $dispatchedAny = false;

        // Custom Emails Dispatch
        if (!empty($emails)) {
            $debug[] = "Custom admin recipient(s): " . implode(', ', $emails);
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$companyName} <{$fromEmail}>\r\n";
            $headers .= "Reply-To: {$fromEmail}\r\n";

            foreach ($emails as $em) {
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $mailSent = @mail($em, $subject, $fullHtml, $headers);
                    $debug[] = "PHP mail() to '{$em}': " . ($mailSent ? 'Success' : 'Failed');
                    if ($mailSent) {
                        $dispatchedAny = true;
                    }
                }
            }
        }

        // WHMCS System Admin Dispatch
        if (function_exists('localAPI')) {
            try {
                $apiRes = localAPI('SendAdminEmail', [
                    'customsubject' => $subject,
                    'custommessage' => $fullHtml,
                    'type' => 'system',
                ], $adminUser);
                $debug[] = "localAPI SendAdminEmail: " . json_encode($apiRes);
                if (strtolower((string) ($apiRes['result'] ?? '')) === 'success') {
                    $dispatchedAny = true;
                }
            } catch (\Throwable $e) {
                $debug[] = "localAPI SendAdminEmail exception: " . $e->getMessage();
            }
        }

        if (function_exists('sendAdminNotification')) {
            try {
                sendAdminNotification('system', $subject, strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $messageHtml)));
                $debug[] = "sendAdminNotification() dispatched";
                $dispatchedAny = true;
            } catch (\Throwable $e) {
                $debug[] = "sendAdminNotification() exception: " . $e->getMessage();
            }
        }

        if (function_exists('logActivity')) {
            logActivity("KYC Admin Alert: {$subject}");
        }

        return ['success' => $dispatchedAny, 'log' => $debug];
    }

    public static function adminNewSubmission(int $verificationId, int $clientId, string $method = 'manual'): bool
    {
        if (cv_setting('mail_admin_new_submission', 'yes') !== 'yes') {
            return false;
        }

        $clientName = 'Client #' . $clientId;
        try {
            $c = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($c) {
                $clientName = trim($c->firstname . ' ' . $c->lastname) . ' (' . $c->email . ')';
            }
        } catch (\Throwable $e) {}

        $reviewUrl = cv_system_url() . '/admin/addonmodules.php?module=clientverification&action=verification&id=' . $verificationId;
        $subject = 'New KYC Submission Requires Review: #' . $verificationId . ' - ' . $clientName;
        $msg = '<p>A new identity verification submission has been received and requires staff review.</p>
        <p><strong>Verification ID:</strong> #' . $verificationId . '<br>
        <strong>Client:</strong> ' . htmlspecialchars($clientName) . '<br>
        <strong>Method:</strong> ' . ucfirst(htmlspecialchars($method)) . '<br>
        <strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>
        <p><a href="' . htmlspecialchars($reviewUrl) . '" style="background: #2563eb; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Review Verification #' . $verificationId . ' &raquo;</a></p>';

        $res = self::notifyAdmin($subject, $msg);
        return $res['success'];
    }

    public static function adminHighRisk(int $verificationId, int $clientId, float $score, array $reasons = []): bool
    {
        if (cv_setting('mail_admin_high_risk', 'yes') !== 'yes') {
            return false;
        }

        $clientName = 'Client #' . $clientId;
        try {
            $c = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($c) {
                $clientName = trim($c->firstname . ' ' . $c->lastname) . ' (' . $c->email . ')';
            }
        } catch (\Throwable $e) {}

        $reviewUrl = cv_system_url() . '/admin/addonmodules.php?module=clientverification&action=verification&id=' . $verificationId;
        $subject = '⚠️ HIGH RISK KYC Alert: #' . $verificationId . ' (Score: ' . $score . '/100) - ' . $clientName;
        $reasonsHtml = '';
        if (!empty($reasons)) {
            $reasonsHtml = '<ul>';
            foreach ($reasons as $r) {
                $reasonsHtml .= '<li>' . htmlspecialchars($r) . '</li>';
            }
            $reasonsHtml .= '</ul>';
        }

        $msg = '<p style="color: #dc2626; font-weight: bold;">A High Risk score was detected for an identity verification.</p>
        <p><strong>Verification ID:</strong> #' . $verificationId . '<br>
        <strong>Client:</strong> ' . htmlspecialchars($clientName) . '<br>
        <strong>Risk Score:</strong> <span style="color: #dc2626; font-weight: bold;">' . $score . ' / 100</span></p>
        ' . ($reasonsHtml ? '<p><strong>Risk Reasons:</strong>' . $reasonsHtml . '</p>' : '') . '
        <p><a href="' . htmlspecialchars($reviewUrl) . '" style="background: #dc2626; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Inspect Verification Now &raquo;</a></p>';

        $res = self::notifyAdmin($subject, $msg);
        return $res['success'];
    }

    public static function adminDiditCompleted(int $verificationId, int $clientId, string $status): bool
    {
        if (cv_setting('mail_admin_didit_completed', 'no') !== 'yes') {
            return false;
        }

        $clientName = 'Client #' . $clientId;
        try {
            $c = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($c) {
                $clientName = trim($c->firstname . ' ' . $c->lastname) . ' (' . $c->email . ')';
            }
        } catch (\Throwable $e) {}

        $reviewUrl = cv_system_url() . '/admin/addonmodules.php?module=clientverification&action=verification&id=' . $verificationId;
        $subject = 'Didit AI Verification Finished: #' . $verificationId . ' (' . ucfirst($status) . ') - ' . $clientName;
        $msg = '<p>Didit AI instant verification has completed processing for a client.</p>
        <p><strong>Verification ID:</strong> #' . $verificationId . '<br>
        <strong>Client:</strong> ' . htmlspecialchars($clientName) . '<br>
        <strong>Result:</strong> ' . ucfirst(htmlspecialchars($status)) . '</p>
        <p><a href="' . htmlspecialchars($reviewUrl) . '" style="background: #16a34a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">View Details &raquo;</a></p>';

        $res = self::notifyAdmin($subject, $msg);
        return $res['success'];
    }

    public static function adminInfoResponse(int $verificationId, int $clientId): bool
    {
        if (cv_setting('mail_admin_info_response', 'yes') !== 'yes') {
            return false;
        }

        $clientName = 'Client #' . $clientId;
        try {
            $c = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($c) {
                $clientName = trim($c->firstname . ' ' . $c->lastname) . ' (' . $c->email . ')';
            }
        } catch (\Throwable $e) {}

        $reviewUrl = cv_system_url() . '/admin/addonmodules.php?module=clientverification&action=verification&id=' . $verificationId;
        $subject = 'Client Re-uploaded Requested Documents: #' . $verificationId . ' - ' . $clientName;
        $msg = '<p>The client has responded to your information request and uploaded updated verification documents.</p>
        <p><strong>Verification ID:</strong> #' . $verificationId . '<br>
        <strong>Client:</strong> ' . htmlspecialchars($clientName) . '</p>
        <p><a href="' . htmlspecialchars($reviewUrl) . '" style="background: #0284c7; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Review Updated Documents &raquo;</a></p>';

        $res = self::notifyAdmin($subject, $msg);
        return $res['success'];
    }
}


