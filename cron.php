<?php

/**
 * Advanced Client Verification - Scheduled tasks.
 * Run manually: php /home/USERNAME/public_html/modules/addons/clientverification/cron.php
 * Or automatically via WHMCS DailyCronJob hook (registered in hooks.php).
 *
 * Tasks: verification expiration, document expiration, email reminders,
 * webhook retry cleanup, temporary file cleanup, retention cleanup.
 */

// Bootstrap WHMCS if running standalone.
if (!class_exists('Illuminate\\Database\\Capsule\\Manager') && file_exists(__DIR__ . '/../../../init.php')) {
    require_once __DIR__ . '/../../../init.php';
}

require_once __DIR__ . '/app/Helpers/functions.php';

function cv_run_cron()
{
    $config = cv_get_config();
    $now = time();
    $expiryDays = (int) ($config['verification_expiry_days'] ?? 365);

    // 0. Periodic License Validation & Update Check with ELMS Server
    if (class_exists('\\ClientVerification\\License\\LicenseManager')) {
        try {
            $licManager = \ClientVerification\License\LicenseManager::getInstance();
            if (!empty($licManager->getLicenseKey())) {
                $licManager->verify(true);
                $licManager->checkUpdate('1.0.0');
            }
        } catch (\Throwable $e) {}
    }

    // 1. Verification expiration.
    $expired = \ClientVerification\Services\VerificationService::expireOverdue();

    // 2. Expiry reminders (7 days before expiry).
    $reminderDate = date('Y-m-d H:i:s', $now + 7 * 86400);
    $due = \Illuminate\Database\Capsule\Manager::table('mod_cv_verifications')
        ->where('status', 'approved')
        ->whereNotNull('expires_at')
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->where('expires_at', '<=', $reminderDate)
        ->get();
    foreach ($due as $v) {
        \ClientVerification\Mail\Notifier::expiring($v->client_id);
    }

    // 3. Document expiration cleanup (delete files past retention).
    $docs = \Illuminate\Database\Capsule\Manager::table('mod_cv_documents')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', date('Y-m-d H:i:s'))
        ->get();
    $storage = new \ClientVerification\Storage\DocumentStorage(
        $config['storage_path'] ?? '',
        (bool) ($config['storage_encryption'] ?? false),
        $config['encryption_key'] ?? ''
    );
    foreach ($docs as $d) {
        $storage->delete($d->storage_path);
        \Illuminate\Database\Capsule\Manager::table('mod_cv_documents')->where('id', $d->id)->delete();
    }

    // 4. Temporary file cleanup (storage/temp).
    $tempDir = ($config['storage_path'] ?? '') . '/temp';
    if (is_dir($tempDir)) {
        foreach (glob($tempDir . '/*') as $f) {
            if (is_file($f) && filemtime($f) < $now - 86400) {
                @unlink($f);
            }
        }
    }

    // 5. Webhook retry: mark failed configs for re-dispatch (best-effort).
    // (OutboundWebhook already retries on next event; here we reset stale failures.)
    \Illuminate\Database\Capsule\Manager::table('mod_cv_webhook_configs')
        ->where('failure_count', '>', 5)
        ->update(['failure_count' => 0]);

    // 6. Webhook event retention: purge raw payloads (possible PII) older than 90 days.
    $retentionCutoff = date('Y-m-d H:i:s', $now - 90 * 86400);
    \Illuminate\Database\Capsule\Manager::table('mod_cv_webhook_events')
        ->where('received_at', '<=', $retentionCutoff)
        ->delete();

    if (function_exists('logActivity')) {
        logActivity('Advanced Client Verification cron completed. Expired: ' . $expired . ', Reminders: ' . count($due) . ', Docs purged: ' . count($docs));
    }

    return ['expired' => $expired, 'reminders' => count($due), 'docs_purged' => count($docs)];
}

// Execute when run via CLI / direct request.
if (PHP_SAPI === 'cli' || (isset($_SERVER['REQUEST_METHOD']) && !headers_sent())) {
    if (class_exists('Illuminate\\Database\\Capsule\\Manager')) {
        $result = cv_run_cron();
        if (PHP_SAPI === 'cli') {
            echo 'Cron complete: ' . json_encode($result) . "\n";
        }
    }
}
