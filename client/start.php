<?php

use ClientVerification\Security\RateLimiter;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Services\HybridVerificationService;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$config = cv_get_config();
$mode = $config['verification_mode'] ?? 'hybrid';

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
    // Didit / automated flow: redirect client to provider.
    header('Location: ' . $result['redirect_url']);
    exit;
}

// Manual / hybrid-without-didit: go to document upload page.
header('Location: index.php?m=clientverification&action=verification&id=' . $result['verification_id']);
exit;
