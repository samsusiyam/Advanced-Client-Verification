<?php

/**
 * Inbound webhook endpoint for Didit (and future providers).
 * URL: /modules/addons/clientverification/api/webhook.php
 *
 * Security: signature verification, timestamp validation, vendor-data validation,
 * replay protection (idempotency via mod_cv_webhook_events) are enforced in
 * DiditWebhookHandler.
 */

require_once __DIR__ . '/../app/Helpers/functions.php';

// External endpoints are hit directly (Didit, REST clients) so WHMCS/Capsule
// must be bootstrapped. Scan upward for WHMCS init.php (layout-independent).
if (!defined('WHMCS')) {
    $dir = __DIR__;
    $bootstrap = null;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir . '/init.php')) {
            $bootstrap = $dir . '/init.php';
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    if ($bootstrap) {
        require_once $bootstrap;
    }
}

if (!defined('WHMCS')) {
    // WHMCS may not be bootstrapped for external webhooks; load Capsule minimally.
    if (!class_exists('Illuminate\\Database\\Capsule\\Manager')) {
        http_response_code(500);
        echo json_encode(['error' => 'bootstrap_failed']);
        exit;
    }
}

use Illuminate\Database\Capsule\Manager as Capsule;

// Handle browser GET callbacks (when Didit redirects the user's browser upon finishing KYC)
$isGet = ($_SERVER['REQUEST_METHOD'] === 'GET');
$sessionId = trim((string)($_GET['verificationSessionId'] ?? ($_GET['session_id'] ?? ($_GET['verification_session_id'] ?? ''))));
$statusParam = trim((string)($_GET['status'] ?? ''));

if ($isGet || !empty($sessionId)) {
    $verificationId = null;
    $loggedInClientId = (int) (($_SESSION['uid'] ?? 0) ?: ($_SESSION['clientsdetails']['userid'] ?? 0));

    // 1. Look up verification by exact didit_session_id
    if ($sessionId) {
        $vRow = Capsule::table('mod_cv_verifications')
            ->where('didit_session_id', $sessionId)
            ->first();

        if ($vRow) {
            $verificationId = $vRow->id;
        }
    }

    // 2. If not matched by session ID, check if currently logged-in client has an active verification
    if (!$verificationId && $loggedInClientId > 0) {
        $vRow = Capsule::table('mod_cv_verifications')
            ->where('client_id', $loggedInClientId)
            ->whereIn('status', ['pending', 'in_progress', 'under_review'])
            ->orderByDesc('id')
            ->first();

        if ($vRow) {
            $verificationId = $vRow->id;
            if ($sessionId && empty($vRow->didit_session_id)) {
                Capsule::table('mod_cv_verifications')
                    ->where('id', $vRow->id)
                    ->update(['didit_session_id' => $sessionId]);
            }
        }
    }

    // 3. Cryptographic / API status validation (Never trust forged GET parameters)
    if ($verificationId && $sessionId) {
        $config = cv_get_config();
        $apiKey = $config['didit_api_key'] ?? ($config['api_key'] ?? '');
        $workflowId = $config['didit_workflow_id'] ?? ($config['workflow_id'] ?? '');

        $result = null;
        if ($apiKey && $workflowId) {
            try {
                $provider = new \ClientVerification\Providers\DiditProvider(
                    $apiKey,
                    $workflowId,
                    $config['didit_webhook_secret'] ?? '',
                    cv_callback_url()
                );

                $result = $provider->getStatus($sessionId);
            } catch (\Throwable $e) {}
        }

        // Apply result ONLY if verified directly from Didit API
        if ($result && in_array($result->decision, [\ClientVerification\Providers\KycResult::DECISION_APPROVED, \ClientVerification\Providers\KycResult::DECISION_DECLINED, \ClientVerification\Providers\KycResult::DECISION_REVIEW], true)) {
            \ClientVerification\Services\HybridVerificationService::applyResult($verificationId, $result);
        } else {
            // Leave in progress / under review until signed POST webhook arrives
            Capsule::table('mod_cv_verifications')
                ->where('id', $verificationId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'in_progress',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    $dest = $verificationId
        ? cv_system_url() . '/index.php?m=clientverification&action=verification&id=' . (int) $verificationId
        : cv_system_url() . '/index.php?m=clientverification';

    header('Location: ' . $dest);
    echo '<script>window.location.href = ' . json_encode($dest) . ';</script>';
    exit;
}

// Inbound Server-to-Server Webhook (POST)
$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $name = str_replace('_', '-', strtolower(substr($k, 5)));
        $headers[$name] = $v;
        $headers[strtolower($k)] = $v;
    }
}

header('Content-Type: application/json');

$config = cv_get_config();
$config['callback_url'] = cv_callback_url();

$result = \ClientVerification\Webhooks\DiditWebhookHandler::handle($rawBody, $headers, $config);

if ($result['success']) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'result' => $result['status'] ?? null]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => $result['error'] ?? 'unknown']);
}
exit;
