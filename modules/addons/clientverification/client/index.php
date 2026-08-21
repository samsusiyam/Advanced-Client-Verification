<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$verification = \ClientVerification\Services\VerificationService::getActiveForClient($clientId);
$config = cv_get_config();
$mode = $config['verification_mode'] ?? 'hybrid';

$statusText = $verification ? $verification->status : 'not_verified';
$approved = ($statusText === 'approved');

echo '<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;text-align:center;">';
echo '<h3>' . Sanitizer::escape($_LANG['cv_title']) . '</h3>';

if ($approved) {
    echo '<div style="color:#10b981;font-size:48px;">&#10003;</div>';
    echo '<h4>' . Sanitizer::escape($_LANG['cv_approved_msg']) . '</h4>';
    echo '<p>Status: <strong>Approved</strong></p>';
} else {
    echo '<p>Status: ' . Sanitizer::escape(ucfirst(str_replace('_', ' ', $statusText))) . '</p>';
    echo '<p>' . Sanitizer::escape($_LANG['cv_verification_required']) . '</p>';
    $btnLabel = ($mode === 'hybrid') ? $_LANG['cv_verify_identity'] : $_LANG['cv_start_verification'];
    echo '<a class="btn btn-primary btn-lg" href="index.php?m=clientverification&action=start">' . Sanitizer::escape($btnLabel) . '</a>';
}
echo '</div>';
