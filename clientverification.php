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
        'description' => 'Production-ready KYC verification with Manual, Didit, and Hybrid modes',
        'version' => '1.0.0',
        'author' => 'Client Verification Team',
        'language' => 'english',
        'fields' => [],
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

    $langFile = __DIR__ . '/lang/english.php';
    if (file_exists($langFile)) {
        require_once $langFile;
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

function cv_insert_default_settings()
{
    $defaults = [
        'enabled' => 'yes',
        'verification_mode' => 'hybrid',
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
    ];

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
}

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
