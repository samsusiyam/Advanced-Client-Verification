<?php

namespace ClientVerification\Mail;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Sends notifications via WHMCS native email (sendMessage / SendEmail) and Admin Alerts.
 */
class Notifier
{
    /**
     * Send client email via WHMCS native templates
     *
     * @param string $template WHMCS email template name
     * @param int    $clientId
     * @param array  $vars
     */
    public static function send(string $template, int $clientId, array $vars = []): bool
    {
        try {
            if (function_exists('localAPI')) {
                $res = localAPI('SendEmail', [
                    'messagename' => $template,
                    'id' => $clientId,
                    'customvars' => $vars,
                ]);
                if (($res['result'] ?? '') === 'success') {
                    return true;
                }
            }
            if (function_exists('sendMessage')) {
                sendMessage($template, $clientId, $vars);
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /* =========================================================================
     * CLIENT / USER NOTIFICATIONS (Checked against module settings)
     * ========================================================================= */

    public static function started(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_started', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Verification Started', $clientId, $vars);
    }

    public static function approved(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_approved', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Verification Approved', $clientId, $vars);
    }

    public static function rejected(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_rejected', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Verification Rejected', $clientId, $vars);
    }

    public static function reviewRequired(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_under_review', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Manual Review Required', $clientId, $vars);
    }

    public static function infoRequired(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_info_requested', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Additional Information Required', $clientId, $vars);
    }

    public static function expiring(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_expiring', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Expiring', $clientId, $vars);
    }

    public static function expired(int $clientId, array $vars = []): bool
    {
        if (cv_setting('mail_client_expired', 'yes') !== 'yes') {
            return false;
        }
        return self::send('KYC Expired', $clientId, $vars);
    }

    /* =========================================================================
     * ADMIN NOTIFICATIONS & ROUTING
     * ========================================================================= */

    /**
     * Send email to configured admin notification email(s) or default WHMCS system admins
     */
    public static function notifyAdmin(string $subject, string $messageHtml): bool
    {
        $customEmails = cv_setting('admin_notification_emails', '');
        $emails = array_filter(array_map('trim', explode(',', (string) $customEmails)));

        if (!empty($emails)) {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: WHMCS KYC System <noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

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

            foreach ($emails as $em) {
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    @mail($em, $subject, $fullHtml, $headers);
                }
            }
            return true;
        }

        try {
            if (function_exists('sendAdminNotification')) {
                sendAdminNotification('system', $subject, strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $messageHtml)));
                return true;
            }
            if (function_exists('localAPI')) {
                localAPI('SendAdminEmail', [
                    'customsubject' => $subject,
                    'custommessage' => $messageHtml,
                ]);
                return true;
            }
        } catch (\Throwable $e) {}

        return false;
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

        return self::notifyAdmin($subject, $msg);
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

        return self::notifyAdmin($subject, $msg);
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

        return self::notifyAdmin($subject, $msg);
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

        return self::notifyAdmin($subject, $msg);
    }
}

