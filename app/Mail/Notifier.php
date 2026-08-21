<?php

namespace ClientVerification\Mail;

/**
 * Sends notifications via WHMCS native email (sendMessage). No custom SMTP.
 */
class Notifier
{
    /**
     * @param string $template WHMCS email template name (without module prefix convention)
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
            // Silently catch all errors to avoid interrupting admin/webhook actions
            return false;
        }
        return false;
    }

    public static function started(int $clientId): bool
    {
        return self::send('KYC Verification Started', $clientId, []);
    }

    public static function approved(int $clientId): bool
    {
        return self::send('KYC Verification Approved', $clientId, []);
    }

    public static function rejected(int $clientId): bool
    {
        return self::send('KYC Verification Rejected', $clientId, []);
    }

    public static function reviewRequired(int $clientId): bool
    {
        return self::send('KYC Manual Review Required', $clientId, []);
    }

    public static function infoRequired(int $clientId): bool
    {
        return self::send('KYC Additional Information Required', $clientId, []);
    }

    public static function expiring(int $clientId): bool
    {
        return self::send('KYC Expiring', $clientId, []);
    }

    public static function expired(int $clientId): bool
    {
        return self::send('KYC Expired', $clientId, []);
    }
}
