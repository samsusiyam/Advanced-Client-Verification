<?php
/**
 * Core helper functions for Advanced Client Verification.
 * Loaded early by the module and migrations.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

// PSR-style autoloader for ClientVerification\* namespaces.
spl_autoload_register(function ($class) {
    $prefix = 'ClientVerification\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

if (!function_exists('cv_executescript')) {
    /**
     * Execute a raw SQL statement (single statement).
     */
    function cv_executescript(string $sql)
    {
        return Capsule::statement($sql);
    }
}

if (!function_exists('cv_setting')) {
    /**
     * Get a module setting value.
     */
    function cv_setting(string $key, $default = null)
    {
        try {
            $row = Capsule::table('mod_cv_settings')
                ->where('setting_key', $key)
                ->first();
            return $row ? $row->setting_value : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('cv_setting_set')) {
    function cv_setting_set(string $key, $value)
    {
        $exists = Capsule::table('mod_cv_settings')->where('setting_key', $key)->exists();
        if ($exists) {
            return Capsule::table('mod_cv_settings')
                ->where('setting_key', $key)
                ->update(['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        return Capsule::table('mod_cv_settings')->insert([
            'setting_key' => $key,
            'setting_value' => $value,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('cv_encrypt_credentials')) {
    /**
     * Encrypt sensitive config using WHMCS local API encryption (open source, no backdoors).
     * Falls back to AES-256-CBC with a derived key if WHMCS encrypt is unavailable.
     */
    function cv_encrypt_credentials(string $plain)
    {
        if (function_exists('encrypt')) {
            try {
                return encrypt($plain);
            } catch (\Exception $e) {
                // fall through
            }
        }
        $key = cv_derive_encryption_key();
        $iv = openssl_random_pseudo_bytes(16);
        $c = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
        return 'cv1:' . base64_encode($iv) . ':' . $c;
    }
}

if (!function_exists('cv_decrypt_credentials')) {
    function cv_decrypt_credentials(string $cipher)
    {
        if (strpos($cipher, 'cv1:') === 0) {
            $parts = explode(':', substr($cipher, 4), 2);
            if (count($parts) === 2) {
                $iv = base64_decode($parts[0]);
                $key = cv_derive_encryption_key();
                $p = openssl_decrypt($parts[1], 'AES-256-CBC', $key, 0, $iv);
                return $p;
            }
        }
        if (function_exists('decrypt')) {
            try {
                return decrypt($cipher);
            } catch (\Exception $e) {
                return '';
            }
        }
        return '';
    }
}

if (!function_exists('cv_derive_encryption_key')) {
    function cv_derive_encryption_key()
    {
        $seed = cv_setting('encryption_key', '');
        if (!$seed) {
            $seed = hash('sha256', 'cv_default_' . (defined('sha1') ? '' : ''));
        }
        return hash('sha256', $seed, true);
    }
}

if (!function_exists('cv_random_token')) {
    function cv_random_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('cv_log_audit')) {
    /**
     * Append an audit entry to a verification's audit log (JSON lines).
     */
    function cv_log_audit(int $verificationId, string $action, int $adminId = 0, string $note = '')
    {
        $row = Capsule::table('mod_cv_verifications')->where('id', $verificationId)->first();
        $log = $row && $row->audit_log ? json_decode($row->audit_log, true) : [];
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'ts' => date('Y-m-d H:i:s'),
            'action' => $action,
            'admin_id' => $adminId,
            'note' => $note,
        ];
        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update([
                'audit_log' => json_encode($log),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        Capsule::table('mod_cv_audit_logs')->insert([
            'verification_id' => $verificationId,
            'admin_id' => $adminId,
            'action' => $action,
            'note' => $note,
            'ip' => \ClientVerification\Security\RateLimiter::getClientIp(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('cv_is_assigned_admin')) {
    function cv_is_assigned_admin(int $verificationId, int $adminId): bool
    {
        $row = Capsule::table('mod_cv_verifications')->where('id', $verificationId)->first();
        if (!$row) {
            return false;
        }
        return $row->assigned_admin_id == $adminId || $row->assigned_admin_id == 0;
    }
}

if (!function_exists('cv_get_config')) {
    /**
     * Read module configuration from tbladdonmodules, decrypting password fields.
     */
    function cv_get_config(): array
    {
        $rows = Capsule::table('tbladdonmodules')
            ->where('module', 'clientverification')
            ->get();

        $config = [];
        $passwordFields = ['didit_api_key', 'didit_webhook_secret', 'encryption_key', 'webhook_outbound_secret', 'api_token'];

        foreach ($rows as $row) {
            $val = $row->value;
            if (in_array($row->setting, $passwordFields, true)) {
                // WHMCS stores module password settings encrypted.
                if (function_exists('decrypt')) {
                    $dec = decrypt($val);
                    $val = ($dec !== false && $dec !== '') ? $dec : $val;
                }
            }
            $config[$row->setting] = $val;
        }

        // Merge with mod_cv_settings for non-module-field settings.
        $settings = Capsule::table('mod_cv_settings')->get();
        foreach ($settings as $s) {
            if (!isset($config[$s->setting_key])) {
                $config[$s->setting_key] = $s->setting_value;
            }
        }

        return $config;
    }
}

if (!function_exists('cv_create_email_templates')) {
    /**
     * Ensure the WHMCS native email templates exist (no custom SMTP needed).
     */
    function cv_create_email_templates()
    {
        $templates = [
            'KYC Verification Started' => ['subject' => 'Identity Verification Started', 'body' => 'Hello, your identity verification process has started. We will notify you once it completes.'],
            'KYC Verification Approved' => ['subject' => 'Identity Verification Approved', 'body' => 'Hello, your identity verification has been approved. Thank you.'],
            'KYC Verification Rejected' => ['subject' => 'Identity Verification Rejected', 'body' => 'Hello, unfortunately your identity verification was rejected. Please contact support.'],
            'KYC Manual Review Required' => ['subject' => 'Identity Verification Under Review', 'body' => 'Hello, your identity verification requires manual review and is currently pending.'],
            'KYC Additional Information Required' => ['subject' => 'Additional Information Required', 'body' => 'Hello, we need additional information to complete your identity verification.'],
            'KYC Expiring' => ['subject' => 'Identity Verification Expiring', 'body' => 'Hello, your identity verification will expire soon. Please renew if required.'],
            'KYC Expired' => ['subject' => 'Identity Verification Expired', 'body' => 'Hello, your identity verification has expired.'],
        ];

        try {
            foreach ($templates as $name => $tpl) {
                $exists = Capsule::table('tblemailtemplates')->where('name', $name)->exists();
                if (!$exists) {
                    Capsule::table('tblemailtemplates')->insert([
                        'type' => 'general',
                        'name' => $name,
                        'subject' => $tpl['subject'],
                        'message' => $tpl['body'],
                        'language' => '',
                        'custom' => 1,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Silently handle schema variations across custom WHMCS installs
        }
    }
}

if (!function_exists('cv_is_client_verified')) {
    /**
     * Returns true if client has an approved, non-expired verification.
     */
    function cv_is_client_verified(int $clientId): bool
    {
        return Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->exists();
    }
}

if (!function_exists('cv_callback_url')) {
    /**
     * Build the public webhook callback URL.
     */
    function cv_callback_url(): string
    {
        $systemUrl = '';
        if (class_exists('\\WHMCS\\Config\\Setting')) {
            try {
                $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
            } catch (\Exception $e) {
                $systemUrl = '';
            }
        }
        if (!$systemUrl) {
            $systemUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return rtrim($systemUrl, '/') . '/modules/addons/clientverification/api/webhook.php';
    }
}

if (!function_exists('cv_kyc_required_for_product')) {
    /**
     * Determine if a product requires KYC based on product + group rules.
     */
    function cv_kyc_required_for_product(int $clientId, int $productId): bool
    {
        // Product-level rule.
        $pRule = Capsule::table('mod_cv_product_rules')->where('product_id', $productId)->first();
        if ($pRule) {
            if ($pRule->requirement === 'required') {
                return true;
            }
            if ($pRule->requirement === 'not_required') {
                return false;
            }
            // optional
            return false;
        }

        // Group-level rule.
        $groupId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('groupid');
        if ($groupId) {
            $gRule = Capsule::table('mod_cv_group_rules')->where('group_id', $groupId)->first();
            if ($gRule) {
                if ($gRule->requirement === 'required') {
                    return true;
                }
                if ($gRule->requirement === 'not_required') {
                    return false;
                }
            }
        }

        // Default: follow global mode requirement.
        $mode = cv_setting('verification_mode', 'hybrid');
        return $mode !== 'disabled';
    }
}
