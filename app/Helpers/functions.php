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

if (!function_exists('cv_insert_default_settings')) {
    function cv_insert_default_settings()
    {
        $defaults = [
            'enabled' => 'yes',
            'verification_mode' => 'hybrid',
            'enable_didit' => 'yes',
            'enable_manual' => 'yes',
            'verification_expiry_days' => '365',
            'rate_limit_attempts' => '5',
            'audit_log_retention_days' => '0',
            'storage_encryption' => '0',
            'max_file_size' => '10',
            'allowed_extensions' => 'jpg,jpeg,png,pdf',
            'risk_threshold_approve' => '30',
            'risk_threshold_review' => '70',
            'mail_client_started' => 'yes',
            'mail_client_approved' => 'yes',
            'mail_client_rejected' => 'yes',
            'mail_client_under_review' => 'yes',
            'mail_client_info_requested' => 'yes',
            'mail_client_expiring' => 'yes',
            'mail_client_expired' => 'yes',
            'mail_admin_new_submission' => 'yes',
            'mail_admin_didit_completed' => 'no',
            'mail_admin_high_risk' => 'yes',
            'mail_admin_info_response' => 'yes',
            'admin_notification_emails' => '',
        ];

        try {
            foreach ($defaults as $k => $v) {
                if (!Capsule::table('mod_cv_settings')->where('setting_key', $k)->exists()) {
                    Capsule::table('mod_cv_settings')->insert([
                        'setting_key' => $k,
                        'setting_value' => $v,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {}
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
        if ($cipher === '') {
            return '';
        }
        if (strpos($cipher, 'cv1:') === 0) {
            $parts = explode(':', substr($cipher, 4), 2);
            if (count($parts) === 2) {
                $iv = base64_decode($parts[0]);
                $key = cv_derive_encryption_key();
                $p = openssl_decrypt($parts[1], 'AES-256-CBC', $key, 0, $iv);
                if ($p !== false && $p !== null) {
                    return $p;
                }
            }
        }
        if (function_exists('decrypt')) {
            try {
                $dec = decrypt($cipher);
                if ($dec !== false && $dec !== '') {
                    return $dec;
                }
            } catch (\Exception $e) {
                // fall through
            }
        }
        return $cipher;
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

        // Auto-prune old logs according to retention settings
        cv_prune_audit_logs();
    }
}

if (!function_exists('cv_prune_audit_logs')) {
    /**
     * Prune audit logs and webhook logs older than configured retention days.
     * If retention is 0 or unset, logs are kept forever.
     */
    function cv_prune_audit_logs(?int $days = null): int
    {
        if ($days === null) {
            $days = (int) cv_setting('audit_log_retention_days', 0);
        }
        if ($days <= 0) {
            return 0; // 0 means keep forever (never auto-delete)
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deleted = Capsule::table('mod_cv_audit_logs')->where('created_at', '<', $cutoff)->delete();
        try {
            Capsule::table('mod_cv_webhook_events')->where('received_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {}

        return (int) $deleted;
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
     * Read module configuration from mod_cv_settings and tbladdonmodules.
     */
    function cv_get_config(): array
    {
        $config = [];
        $passwordFields = ['didit_api_key', 'didit_webhook_secret', 'encryption_key', 'webhook_outbound_secret', 'api_token'];

        // 1. Primary source: mod_cv_settings
        try {
            $settings = Capsule::table('mod_cv_settings')->get();
            foreach ($settings as $s) {
                $val = $s->setting_value;
                $config[$s->setting_key] = $val;
            }
        } catch (\Exception $e) {}

        // 2. Fallback / legacy merge from tbladdonmodules
        try {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', 'clientverification')
                ->get();
            foreach ($rows as $row) {
                if (!isset($config[$row->setting]) || $config[$row->setting] === '') {
                    $val = $row->value;
                    if (in_array($row->setting, $passwordFields, true) && function_exists('decrypt')) {
                        $dec = decrypt($val);
                        $val = ($dec !== false && $dec !== '') ? $dec : $val;
                    }
                    $config[$row->setting] = $val;
                }
            }
        } catch (\Exception $e) {}

        // 3. Normalize Didit configuration aliases so core engine always has both
        if (!empty($config['didit_api_key'])) {
            $config['api_key'] = $config['didit_api_key'];
        } elseif (!empty($config['api_key'])) {
            $config['didit_api_key'] = $config['api_key'];
        }

        if (!empty($config['didit_workflow_id'])) {
            $config['workflow_id'] = $config['didit_workflow_id'];
        } elseif (!empty($config['workflow_id'])) {
            $config['didit_workflow_id'] = $config['workflow_id'];
        }

        if (!empty($config['didit_webhook_secret'])) {
            $config['webhook_secret'] = $config['didit_webhook_secret'];
        } elseif (!empty($config['webhook_secret'])) {
            $config['didit_webhook_secret'] = $config['webhook_secret'];
        }

        $config['callback_url'] = $config['callback_url'] ?? cv_callback_url();

        return $config;
    }
}

if (!function_exists('cv_create_email_templates')) {
    /**
     * Ensure the WHMCS native email templates exist with professional responsive HTML styling.
     */
    function cv_create_email_templates()
    {
        $templates = [
            'KYC Verification Started' => [
                'subject' => 'Identity Verification Started - {$company_name}',
                'body' => '<p>Dear {$client_name},</p><p>Your identity verification process has been initiated for your account with <strong>{$company_name}</strong>.</p><p>Please follow the instructions on your screen or client area to submit the required identification documents.</p><p><a href="{$verification_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Complete Verification &raquo;</a></p><p>If you did not initiate this request, please contact our support team immediately.</p><p>Regards,<br>{$company_name}</p>'
            ],
            'KYC Verification Approved' => [
                'subject' => 'Identity Verification Approved - {$company_name}',
                'body' => '<p>Dear {$client_name},</p><p>Great news! Your identity verification has been reviewed and <strong style="color: #16a34a;">Approved</strong>.</p><p>Your account is now fully verified and in good standing with <strong>{$company_name}</strong>.</p><p><a href="{$verification_url}" style="background: #16a34a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">View Verification Status &raquo;</a></p><p>Thank you for your cooperation.</p><p>Regards,<br>{$company_name}</p>'
            ],
            'KYC Verification Rejected' => [
                'subject' => 'Identity Verification Update - Action Required',
                'body' => '<p>Dear {$client_name},</p><p>We regret to inform you that your identity verification submission could not be approved at this time.</p><p><strong>Reason:</strong> {$reason}</p><p>You may log in to your client area and submit a new verification with valid, clear documents.</p><p><a href="{$verification_url}" style="background: #dc2626; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Submit New Verification &raquo;</a></p><p>If you have any questions, please reach out to our support department.</p><p>Regards,<br>{$company_name}</p>'
            ],
            'KYC Manual Review Required' => [
                'subject' => 'Identity Verification Received - Under Compliance Review',
                'body' => '<p>Dear {$client_name},</p><p>We have successfully received your identity verification submission. Your documents are currently under review by our compliance team.</p><p>We will notify you by email as soon as the review is complete.</p><p><a href="{$verification_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Check Status &raquo;</a></p><p>Regards,<br>{$company_name}</p>'
            ],
            'KYC Additional Information Required' => [
                'subject' => 'Action Required: Additional Information Needed for Verification',
                'body' => '<p>Dear {$client_name},</p><p>Our compliance team requires additional information or clearer documents to finalize your identity verification.</p><p><strong>Staff Note:</strong> {$note}</p><p>Please log in and upload the requested documents at your earliest convenience:</p><p><a href="{$verification_url}" style="background: #0284c7; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Upload Requested Information &raquo;</a></p><p>Regards,<br>{$company_name}</p>'
            ],
            'KYC Expiring' => [
                'subject' => 'Reminder: Your Identity Verification Is Expiring Soon',
                'body' => '<p>Dear {$client_name},</p><p>This is a courtesy reminder that your annual identity verification with <strong>{$company_name}</strong> will expire on <strong>{$expiry_date}</strong>.</p><p>To prevent any disruption to your services, please renew your verification:</p><p><a href="{$verification_url}" style="background: #d97706; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Renew Verification &raquo;</a></p><p>Regards,<br>{$company_name}</p>'
            ],
            'KYC Expired' => [
                'subject' => 'Important: Your Identity Verification Has Expired',
                'body' => '<p>Dear {$client_name},</p><p>Your identity verification with <strong>{$company_name}</strong> has expired.</p><p>Please submit updated verification documents to maintain active services on your account:</p><p><a href="{$verification_url}" style="background: #dc2626; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Re-verify Identity Now &raquo;</a></p><p>Regards,<br>{$company_name}</p>'
            ],
        ];

        try {
            foreach ($templates as $name => $tpl) {
                $row = Capsule::table('tblemailtemplates')->where('name', $name)->first();
                if (!$row) {
                    Capsule::table('tblemailtemplates')->insert([
                        'type' => 'general',
                        'name' => $name,
                        'subject' => $tpl['subject'],
                        'message' => $tpl['body'],
                        'language' => '',
                        'custom' => 1,
                        'disabled' => 0,
                    ]);
                }
            }
        } catch (\Throwable $e) {
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

if (!function_exists('cv_system_url')) {
    /**
     * Get base WHMCS System URL.
     */
    function cv_system_url(): string
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
        return rtrim($systemUrl, '/');
    }
}

if (!function_exists('cv_callback_url')) {
    /**
     * Build the browser redirect callback URL for when client finishes Didit verification.
     */
    function cv_callback_url(): string
    {
        return cv_system_url() . '/index.php?m=clientverification&action=callback';
    }
}

if (!function_exists('cv_webhook_url')) {
    /**
     * Build the public inbound server-to-server webhook destination URL for Didit events.
     */
    function cv_webhook_url(): string
    {
        return cv_system_url() . '/modules/addons/clientverification/api/webhook.php';
    }
}

if (!function_exists('cv_kyc_required_for_product')) {
    /**
     * Determine if a product requires KYC based on product + group rules.
     */
    function cv_kyc_required_for_product(int $clientId, int $productId): bool
    {
        if (cv_setting('enabled', 'yes') !== 'yes') {
            return false;
        }

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

if (!function_exists('cv_admin_header')) {
    /**
     * Render unified, modern admin navigation header across all module pages.
     */
    function cv_admin_header(string $activeTab = 'dashboard', string $title = '', string $subtitle = ''): void
    {
        $pendingCount = 0;
        try {
            $pendingCount = Capsule::table('mod_cv_verifications')
                ->whereIn('status', ['pending', 'under_review'])
                ->count();
        } catch (\Exception $e) {}

        $navTabs = [
            'dashboard' => ['label' => 'Dashboard', 'icon' => 'fa-tachometer'],
            'verifications' => ['label' => 'Verifications', 'icon' => 'fa-id-card', 'badge' => $pendingCount],
            'documents' => ['label' => 'Documents', 'icon' => 'fa-folder-open'],
            'settings' => ['label' => 'Settings', 'icon' => 'fa-cogs'],
            'product-rules' => ['label' => 'Product Rules', 'icon' => 'fa-cube'],
            'group-rules' => ['label' => 'Group Rules', 'icon' => 'fa-users'],
            'webhooks' => ['label' => 'Webhooks', 'icon' => 'fa-bolt'],
            'api' => ['label' => 'API Tokens', 'icon' => 'fa-key'],
            'audit-logs' => ['label' => 'Audit Logs', 'icon' => 'fa-history'],
            'exports' => ['label' => 'Exports', 'icon' => 'fa-download'],
        ];

        echo '<div class="cv-admin-wrapper" style="margin-bottom: 22px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">';
        
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 10px;">';
        echo '<div>';
        echo '<h2 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">';
        echo '<span style="color: #2563eb;"><i class="fa fa-shield"></i></span> Advanced Client Verification';
        if ($title) {
            echo '<span style="color: #cbd5e1; font-weight: 300;"> / </span> <span style="font-size: 19px; font-weight: 600; color: #334155;">' . htmlspecialchars($title) . '</span>';
        }
        echo '</h2>';
        if ($subtitle) {
            echo '<p style="margin: 4px 0 0 0; color: #64748b; font-size: 13px;">' . htmlspecialchars($subtitle) . '</p>';
        }
        echo '</div>';
        
        $mode = cv_setting('verification_mode', 'hybrid');
        $enabled = cv_setting('enabled', 'yes') === 'yes';
        $statusColor = $enabled ? '#10b981' : '#ef4444';
        $statusText = $enabled ? 'Active' : 'Disabled';
        
        echo '<div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #475569; background: #f8fafc; padding: 6px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">';
        echo '<span>Status: <strong style="color: ' . $statusColor . ';">' . $statusText . '</strong></span>';
        echo '<span style="color: #cbd5e1;">|</span>';
        echo '<span>Mode: <strong style="text-transform: uppercase; color: #2563eb;">' . htmlspecialchars($mode) . '</strong></span>';
        echo '<span style="color: #cbd5e1;">|</span>';
        echo '<span>v1.0.0</span>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; background: #ffffff; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">';
        foreach ($navTabs as $tab => $item) {
            $isActive = ($activeTab === $tab);
            $bg = $isActive ? '#2563eb' : '#f8fafc';
            $color = $isActive ? '#ffffff' : '#334155';
            $border = $isActive ? '#1d4ed8' : '#e2e8f0';
            $weight = $isActive ? '600' : '500';
            
            echo '<a href="addonmodules.php?module=clientverification&action=' . urlencode($tab) . '" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 13px; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: ' . $weight . '; background: ' . $bg . '; color: ' . $color . '; border: 1px solid ' . $border . '; transition: all 0.15s ease;">';
            echo '<i class="fa ' . $item['icon'] . '"></i> ' . htmlspecialchars($item['label']);
            if (!empty($item['badge']) && $item['badge'] > 0) {
                $badgeBg = $isActive ? '#ffffff' : '#ef4444';
                $badgeColor = $isActive ? '#dc2626' : '#ffffff';
                echo ' <span style="background: ' . $badgeBg . '; color: ' . $badgeColor . '; font-size: 11px; padding: 1px 6px; border-radius: 10px; font-weight: 700; margin-left: 2px;">' . (int)$item['badge'] . '</span>';
            }
            echo '</a>';
        }
        echo '</div>';
        echo '</div>';
    }
}

if (!function_exists('cv_render_pagination')) {
    /**
     * Safe HTML pagination renderer for WHMCS (avoids Laravel view dependency issues).
     */
    function cv_render_pagination(int $total, int $perPage, int $currentPage, string $baseUrl): string
    {
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; gap: 10px;">';
        $from = (($currentPage - 1) * $perPage) + 1;
        $to = min($total, $currentPage * $perPage);
        $html .= '<div style="font-size: 13px; color: #64748b;">Showing <strong>' . $from . '</strong> to <strong>' . $to . '</strong> of <strong>' . $total . '</strong> entries</div>';
        $html .= '<div style="display: flex; gap: 4px;">';

        if ($currentPage > 1) {
            $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage - 1) . '" class="btn btn-default btn-sm">&laquo; Prev</a>';
        }

        for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++) {
            $cls = ($p === $currentPage) ? 'btn-primary' : 'btn-default';
            $html .= '<a href="' . $baseUrl . '&page=' . $p . '" class="btn ' . $cls . ' btn-sm">' . $p . '</a>';
        }

        if ($currentPage < $totalPages) {
            $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage + 1) . '" class="btn btn-default btn-sm">Next &raquo;</a>';
        }

        $html .= '</div></div>';
        return $html;
    }
}

