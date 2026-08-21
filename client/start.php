<?php

use ClientVerification\Security\RateLimiter;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Services\HybridVerificationService;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$activeV = \ClientVerification\Services\VerificationService::getActiveForClient($clientId);
if ($activeV && $activeV->status === 'suspended') {
    if (!headers_sent()) {
        header('Location: index.php?m=clientverification');
    }
    echo '<script>window.location.href = "index.php?m=clientverification";</script>';
    exit;
}

$config = cv_get_config();
$enableDidit = cv_setting('enable_didit', 'yes') === 'yes' && !empty($config['didit_api_key'] ?? '') && !empty($config['didit_workflow_id'] ?? '');
$enableManual = cv_setting('enable_manual', 'yes') === 'yes';
$globalMode = $config['verification_mode'] ?? 'hybrid';

$canDidit = $enableDidit && in_array($globalMode, ['hybrid', 'didit']);
$canManual = $enableManual && in_array($globalMode, ['hybrid', 'manual']);

$requestedMethod = $_GET['method'] ?? '';

if ($requestedMethod === 'manual' && $canManual) {
    $mode = 'manual';
} elseif ($requestedMethod === 'didit' && $canDidit) {
    $mode = 'didit';
} elseif ($canDidit && $canManual) {
    // Both are enabled and no method explicitly requested -> Show selection screen
    if (!headers_sent()) {
        header('Location: index.php?m=clientverification');
    }
    echo '<script>window.location.href = "index.php?m=clientverification";</script>';
    exit;
} elseif ($canDidit) {
    $mode = 'didit';
} else {
    $mode = 'manual';
}

// Rate limit: max attempts from config (default 5/hour).
$max = (int) ($config['rate_limit_attempts'] ?? 5);
$ip = RateLimiter::getClientIp();
if (!RateLimiter::attempt('verification_start', 'client_' . $clientId, $max, 3600)
    || !RateLimiter::attempt('verification_start', 'ip_' . $ip, $max, 3600)) {
    echo '<div class="alert alert-danger">Too many verification attempts. Please try again later.</div>';
    return;
}

// Gather minimal personal data already known from WHMCS client record.
$client = \Illuminate\Database\Capsule\Manager::table('tblclients')->where('id', $clientId)->first();
$personal = [];
if ($client) {
    $personal = [
        'first_name' => $client->firstname,
        'last_name' => $client->lastname,
        'phone' => $client->phonenumber,
        'address' => $client->address1,
        'city' => $client->city,
        'state' => $client->state,
        'postal_code' => $client->postcode,
        'country' => $client->country,
    ];
}

$result = HybridVerificationService::start($clientId, $mode, $personal, $config);

if (!empty($result['redirect_url'])) {
    if (!headers_sent()) {
        header('Location: ' . $result['redirect_url']);
    }
    echo '<script>window.location.href = ' . json_encode($result['redirect_url']) . ';</script>';
    echo '<div style="text-align: center; padding: 40px;"><p>Redirecting to identity verification...</p><a href="' . htmlspecialchars($result['redirect_url']) . '" class="btn btn-primary">Click here if not redirected</a></div>';
    exit;
}

$dest = 'index.php?m=clientverification&action=verification&id=' . (int) $result['verification_id'];
if (!headers_sent()) {
    header('Location: ' . $dest);
}
echo '<script>window.location.href = ' . json_encode($dest) . ';</script>';
echo '<div style="text-align: center; padding: 40px;"><p>Redirecting to document upload...</p><a href="' . htmlspecialchars($dest) . '" class="btn btn-primary">Continue</a></div>';
exit;
