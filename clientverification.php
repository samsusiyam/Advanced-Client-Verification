<?php

use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be directly accessed');
}

require_once __DIR__ . '/app/Helpers/functions.php';
require_once __DIR__ . '/app/Providers/KycProviderInterface.php';

function clientverification_config()
{
    return [
        'name' => 'Advanced Client Verification',
        'displayName' => 'Advanced Client Verification',
        'description' => 'Enterprise-grade automated and manual KYC verification module for WHMCS by HostNibo.',
        'version' => '1.0.0',
        'author' => 'HostNibo',
        'category' => 'Security',
        'language' => 'english',
        'fields' => [
            'license_key' => [
                'FriendlyName' => 'License Key',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Your HostNibo module license key (from <a href="https://hostnibo.com" target="_blank">HostNibo Client Area</a>).',
            ],
        ],
    ];
}

function clientverification_activate()
{
    $migrations = [
        '001_create_verifications_table',
        '002_create_personal_data_table',
        '003_create_documents_table',
        '004_create_product_rules_table',
        '005_create_group_rules_table',
        '006_create_audit_logs_table',
        '007_create_webhook_events_table',
        '008_create_webhook_configs_table',
        '009_create_api_tokens_table',
        '010_create_settings_table',
        '011_create_document_types_table',
        '012_create_rate_limits_table',
    ];

    $migrationPath = __DIR__ . '/database/migrations/';

    foreach ($migrations as $migration) {
        $file = $migrationPath . $migration . '.php';
        if (file_exists($file)) {
            require_once $file;
            $function = 'migration_' . $migration;
            if (function_exists($function)) {
                try {
                    $function();
                } catch (\Exception $e) {
                    return ['status' => 'error', 'description' => "Migration failed: {$migration} - " . $e->getMessage()];
                }
            }
        }
    }

    try {
        cv_insert_default_settings();
        cv_insert_default_document_types();
        cv_create_email_templates();
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Initialization error: ' . $e->getMessage()];
    }

    return ['status' => 'success', 'description' => 'Advanced Client Verification activated successfully'];
}

function clientverification_deactivate()
{
    return ['status' => 'success', 'description' => 'Module deactivated. Data preserved.'];
}

function clientverification_upgrade($vars)
{
    $version = $vars['version'] ?? '1.0.0';
    return ['status' => 'success', 'description' => 'Upgraded to ' . $version];
}

function clientverification_output($vars)
{
    $action = $_GET['action'] ?? 'dashboard';

    // Sync license key from addon settings if present
    if (!empty($vars['license_key'])) {
        $storedKey = cv_setting('license_key', '');
        if (empty($storedKey) || $storedKey !== trim($vars['license_key'])) {
            \ClientVerification\License\LicenseManager::getInstance()->saveLicenseKey(trim($vars['license_key']));
        }
    }

    // Ensure database columns are up-to-date
    try {
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'document_number')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->string('document_number', 100)->nullable()->after('client_ref');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_personal_data', 'document_number')) {
            Capsule::schema()->table('mod_cv_personal_data', function ($table) {
                $table->string('document_number', 100)->nullable()->after('verification_id');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'info_request_note')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->text('info_request_note')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'rejection_reason')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->text('rejection_reason')->nullable();
            });
        }
    } catch (\Throwable $e) {}

    // Ensure email templates & settings exist
    if (function_exists('cv_create_email_templates')) {
        cv_create_email_templates();
    }
    if (function_exists('cv_insert_default_settings')) {
        cv_insert_default_settings();
    }

    $langFile = __DIR__ . '/lang/english.php';
    if (file_exists($langFile)) {
        require_once $langFile;
    }

    // License Gatekeeper: If unlicensed and not activating, enforce License activation screen
    $isLicensed = cv_is_licensed();
    if (!$isLicensed && $action !== 'license') {
        // If POST request to activate/save is present, load license page to process it
        require_once __DIR__ . '/admin/license.php';
        return;
    }

    switch ($action) {
        case 'dashboard':
            require_once __DIR__ . '/admin/dashboard.php';
            break;
        case 'verifications':
            require_once __DIR__ . '/admin/verifications.php';
            break;
        case 'verification':
            require_once __DIR__ . '/admin/verification.php';
            break;
        case 'settings':
            require_once __DIR__ . '/admin/settings.php';
            break;
        case 'license':
            require_once __DIR__ . '/admin/license.php';
            break;
        case 'product-rules':
            require_once __DIR__ . '/admin/product-rules.php';
            break;
        case 'group-rules':
            require_once __DIR__ . '/admin/group-rules.php';
            break;
        case 'audit-logs':
            require_once __DIR__ . '/admin/audit-logs.php';
            break;
        case 'webhooks':
            require_once __DIR__ . '/admin/webhooks.php';
            break;
        case 'api':
            require_once __DIR__ . '/admin/api.php';
            break;
        case 'documents':
            require_once __DIR__ . '/admin/documents.php';
            break;
        case 'exports':
            require_once __DIR__ . '/admin/exports.php';
            break;
        default:
            require_once __DIR__ . '/admin/dashboard.php';
            break;
    }
}

function clientverification_clientarea($vars)
{
    // License fail-safe: gracefully notify if module is inactive
    if (!cv_is_licensed()) {
        return [
            'pagetitle' => 'Identity Verification',
            'breadcrumb' => [
                'index.php?m=clientverification' => 'Identity Verification',
            ],
            'templatefile' => 'templates/clientarea',
            'requirelogin' => true,
            'vars' => [
                'content' => '<div style="max-width: 580px; margin: 40px auto; background: #ffffff; border: 1px solid #fed7aa; border-radius: 10px; padding: 30px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.03);"><div style="width: 54px; height: 54px; background: #ffedd5; color: #ea580c; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 24px;"><i class="fa fa-info-circle"></i></div><h3 style="margin: 0 0 8px 0; color: #9a3412; font-size: 18px; font-weight: 700;">Service Notice</h3><p style="color: #64748b; font-size: 14px; margin: 0;">Identity verification is currently undergoing scheduled system maintenance. Please check back shortly.</p></div>',
            ],
        ];
    }

    $action = $_GET['action'] ?? 'index';

    $langFile = __DIR__ . '/lang/english.php';
    if (file_exists($langFile)) {
        require_once $langFile;
    }

    ob_start();
    switch ($action) {
        case 'index':
            require __DIR__ . '/client/index.php';
            break;
        case 'verification':
            require __DIR__ . '/client/verification.php';
            break;
        case 'start':
            require __DIR__ . '/client/start.php';
            break;
        case 'document':
            require __DIR__ . '/client/document.php';
            break;
        case 'callback':
            require __DIR__ . '/client/callback.php';
            break;
        default:
            require __DIR__ . '/client/index.php';
            break;
    }
    $content = ob_get_clean();

    return [
        'pagetitle' => 'Identity Verification',
        'breadcrumb' => [
            'index.php?m=clientverification' => 'Identity Verification',
        ],
        'templatefile' => 'templates/clientarea',
        'requirelogin' => true,
        'vars' => [
            'content' => $content,
        ],
    ];
}

if (!function_exists('cv_insert_default_settings')) {
    function cv_insert_default_settings()
    {
        $defaults = [
            'enabled' => 'yes',
            'verification_mode' => 'hybrid',
            'enable_didit' => 'yes',
            'enable_manual' => 'yes',
            'didit_api_key' => '',
            'didit_workflow_id' => '',
            'didit_webhook_secret' => '',
            'didit_auto_approve' => '1',
            'didit_on_error' => 'manual_review',
            'storage_path' => '',
            'storage_encryption' => '0',
            'encryption_key' => '',
            'max_file_size' => '10',
            'allowed_extensions' => 'pdf,jpg,jpeg,png,webp',
            'verification_expiry_days' => '365',
            'risk_threshold_approve' => '30',
            'risk_threshold_review' => '70',
            'rate_limit_attempts' => '5',
            'webhook_outbound_secret' => '',
            'api_token' => '',
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
            foreach ($defaults as $key => $value) {
                $exists = Capsule::table('mod_cv_settings')->where('setting_key', $key)->exists();
                if (!$exists) {
                    Capsule::table('mod_cv_settings')->insert([
                        'setting_key' => $key,
                        'setting_value' => $value,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('cv_insert_default_document_types')) {
    function cv_insert_default_document_types()
    {
        $types = [
            ['name' => 'national_id', 'label' => 'National ID Card', 'sides' => 2, 'required' => 1],
            ['name' => 'passport', 'label' => 'Passport', 'sides' => 1, 'required' => 1],
            ['name' => 'drivers_license', 'label' => "Driver's License", 'sides' => 2, 'required' => 1],
            ['name' => 'birth_certificate', 'label' => 'Birth Certificate', 'sides' => 1, 'required' => 1],
        ];

        try {
            Capsule::table('mod_cv_document_types')->whereNotIn('name', ['national_id', 'passport', 'drivers_license', 'birth_certificate'])->delete();

            foreach ($types as $type) {
                Capsule::table('mod_cv_document_types')->updateOrInsert(
                    ['name' => $type['name']],
                    [
                        'label' => $type['label'],
                        'sides_required' => $type['sides'],
                        'is_required' => $type['required'],
                        'created_at' => date('Y-m-d H:i:s'),
                    ]
                );
            }
        } catch (\Throwable $e) {}
    }
}
