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

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (empty($headers)) {
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace('_', '-', strtolower(substr($k, 5)));
            $headers[$name] = $v;
        }
    }
}

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
