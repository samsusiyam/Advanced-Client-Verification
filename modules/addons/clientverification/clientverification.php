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
        'fields' => [
            'enabled' => [
                'FriendlyName' => 'Module Enabled',
                'Type' => 'yesno',
                'Description' => 'Enable/disable the verification module',
                'Default' => 'yes',
            ],
            'verification_mode' => [
                'FriendlyName' => 'Verification Mode',
                'Type' => 'dropdown',
                'Options' => [
                    'hybrid' => 'Hybrid (Recommended)',
                    'manual' => 'Manual Only',
                    'didit' => 'Didit Automated Only',
                ],
                'Default' => 'hybrid',
                'Description' => 'Select the default verification mode',
            ],
            'didit_api_key' => [
                'FriendlyName' => 'Didit API Key',
                'Type' => 'password',
                'Description' => 'Your Didit API key',
            ],
            'didit_workflow_id' => [
                'FriendlyName' => 'Didit Workflow ID',
                'Type' => 'text',
                'Description' => 'Didit workflow identifier',
            ],
            'didit_webhook_secret' => [
                'FriendlyName' => 'Didit Webhook Secret',
                'Type' => 'password',
                'Description' => 'Secret for verifying Didit webhooks',
            ],
            'didit_auto_approve' => [
                'FriendlyName' => 'Auto Approve on Didit Success',
                'Type' => 'yesno',
                'Description' => 'Automatically approve when Didit returns approved status',
                'Default' => 'yes',
            ],
            'didit_on_error' => [
                'FriendlyName' => 'On Didit Provider Error',
                'Type' => 'dropdown',
                'Options' => [
                    'manual_review' => 'Manual Review',
                    'reject' => 'Reject',
                ],
                'Default' => 'manual_review',
                'Description' => 'Action when Didit provider encounters an error',
            ],
            'storage_path' => [
                'FriendlyName' => 'Document Storage Path',
                'Type' => 'text',
                'Description' => 'Absolute path for document storage (outside public_html)',
                'Default' => '/home/{username}/kyc-storage',
            ],
            'storage_encryption' => [
                'FriendlyName' => 'Enable Document Encryption',
                'Type' => 'yesno',
                'Description' => 'Encrypt stored documents at rest',
                'Default' => 'no',
            ],
            'encryption_key' => [
                'FriendlyName' => 'Encryption Key',
                'Type' => 'password',
                'Description' => 'Key for document encryption (32 chars recommended)',
            ],
            'max_file_size' => [
                'FriendlyName' => 'Max File Size (MB)',
                'Type' => 'text',
                'Description' => 'Maximum upload file size in megabytes',
                'Default' => '10',
            ],
            'allowed_extensions' => [
                'FriendlyName' => 'Allowed File Extensions',
                'Type' => 'text',
                'Description' => 'Comma-separated allowed extensions',
                'Default' => 'pdf,jpg,jpeg,png,webp',
            ],
            'verification_expiry_days' => [
                'FriendlyName' => 'Verification Expiry (Days)',
                'Type' => 'text',
                'Description' => 'Number of days before verification expires',
                'Default' => '365',
            ],
            'risk_threshold_approve' => [
                'FriendlyName' => 'Auto-Approve Risk Threshold',
                'Type' => 'text',
                'Description' => 'Risk score below this value auto-approves (0-100)',
                'Default' => '30',
            ],
            'risk_threshold_review' => [
                'FriendlyName' => 'Manual Review Risk Threshold',
                'Type' => 'text',
                'Description' => 'Risk score below this value requires manual review (0-100)',
                'Default' => '70',
            ],
            'rate_limit_attempts' => [
                'FriendlyName' => 'Rate Limit: Max Attempts',
                'Type' => 'text',
                'Description' => 'Maximum verification attempts per hour',
                'Default' => '5',
            ],
            'webhook_outbound_secret' => [
                'FriendlyName' => 'Outbound Webhook Secret',
                'Type' => 'password',
                'Description' => 'Secret for signing outbound webhooks',
            ],
            'api_token' => [
                'FriendlyName' => 'API Access Token',
                'Type' => 'password',
                'Description' => 'Token for API access',
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
                    return ['success' => false, 'message' => "Migration failed: {$migration} - " . $e->getMessage()];
                }
            }
        }
    }

    cv_insert_default_settings();
    cv_insert_default_document_types();
    cv_create_email_templates();

    return ['success' => true, 'message' => 'Advanced Client Verification activated successfully'];
}

function clientverification_deactivate()
{
    return ['success' => true, 'message' => 'Module deactivated. Data preserved.'];
}

function clientverification_output($vars)
{
    $action = $_GET['action'] ?? 'dashboard';

    $adminId = (int) Capsule::table('tbladmins')->where('id', $_SESSION['adminid'])->value('id');
    if (!$adminId) {
        die('Unauthorized');
    }

    $templatesPath = __DIR__ . '/templates/admin/';
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

    $clientId = (int) $_SESSION['clientsdetails']['userid'] ?? 0;
    if (!$clientId) {
        header('Location: clientarea.php');
        exit;
    }

    $langFile = __DIR__ . '/lang/english.php';
    if (file_exists($langFile)) {
        require_once $langFile;
    }

    switch ($action) {
        case 'index':
            require_once __DIR__ . '/client/index.php';
            break;
        case 'verification':
            require_once __DIR__ . '/client/verification.php';
            break;
        case 'start':
            require_once __DIR__ . '/client/start.php';
            break;
        case 'document':
            require_once __DIR__ . '/client/document.php';
            break;
        default:
            require_once __DIR__ . '/client/index.php';
            break;
    }
}

function cv_insert_default_settings()
{
    $defaults = [
        'verification_mode' => 'hybrid',
        'didit_auto_approve' => '1',
        'didit_on_error' => 'manual_review',
        'max_file_size' => '10',
        'allowed_extensions' => 'pdf,jpg,jpeg,png,webp',
        'verification_expiry_days' => '365',
        'risk_threshold_approve' => '30',
        'risk_threshold_review' => '70',
        'rate_limit_attempts' => '5',
        'storage_encryption' => '0',
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
        ['name' => 'passport', 'label' => 'Passport', 'sides' => 1, 'required' => 1],
        ['name' => 'drivers_license', 'label' => "Driver's License", 'sides' => 2, 'required' => 1],
        ['name' => 'national_id', 'label' => 'National ID Card', 'sides' => 2, 'required' => 1],
        ['name' => 'selfie', 'label' => 'Selfie Photo', 'sides' => 1, 'required' => 1],
        ['name' => 'proof_of_address', 'label' => 'Proof of Address', 'sides' => 1, 'required' => 1],
    ];

    foreach ($types as $type) {
        $exists = Capsule::table('mod_cv_document_types')->where('name', $type['name'])->exists();
        if (!$exists) {
            Capsule::table('mod_cv_document_types')->insert([
                'name' => $type['name'],
                'label' => $type['label'],
                'sides_required' => $type['sides'],
                'is_required' => $type['required'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
