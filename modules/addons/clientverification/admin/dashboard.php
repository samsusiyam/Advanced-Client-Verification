<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$stats = [
    'total' => Capsule::table('mod_cv_verifications')->count(),
    'pending' => Capsule::table('mod_cv_verifications')->where('status', 'pending')->count(),
    'under_review' => Capsule::table('mod_cv_verifications')->where('status', 'under_review')->count(),
    'approved' => Capsule::table('mod_cv_verifications')->where('status', 'approved')->count(),
    'rejected' => Capsule::table('mod_cv_verifications')->where('status', 'rejected')->count(),
    'expired' => Capsule::table('mod_cv_verifications')->where('status', 'expired')->count(),
];

$diditApproved = Capsule::table('mod_cv_verifications')->where('verification_method', 'didit')->where('status', 'approved')->count();
$manualApproved = Capsule::table('mod_cv_verifications')->where('verification_method', 'manual')->where('status', 'approved')->count();
$manualReviews = Capsule::table('mod_cv_verifications')->where('manual_review_required', 1)->count();
$providerErrors = Capsule::table('mod_cv_verifications')->where('didit_decision', 'error')->count();

function cv_card($label, $value, $color)
{
    return '<div style="background:#fff;border:1px solid #ddd;border-left:4px solid ' . $color . ';border-radius:6px;padding:16px;min-width:160px;flex:1;">
        <div style="font-size:13px;color:#666;">' . Sanitizer::escape($label) . '</div>
        <div style="font-size:26px;font-weight:700;margin-top:6px;">' . Sanitizer::escape($value) . '</div>
    </div>';
}

echo '<h2>' . Sanitizer::escape($_LANG['cv_title']) . '</h2>';

$navLinks = [
    'dashboard' => $_LANG['cv_dashboard'],
    'verifications' => $_LANG['cv_verifications'],
    'documents' => $_LANG['cv_documents_mgmt'],
    'webhooks' => $_LANG['cv_webhooks'],
    'api' => $_LANG['cv_api_tokens'],
    'audit-logs' => $_LANG['cv_audit_logs'],
    'product-rules' => $_LANG['cv_product_rules'],
    'group-rules' => $_LANG['cv_group_rules'],
    'settings' => $_LANG['cv_settings'],
    'exports' => $_LANG['cv_exports'],
];
echo '<div style="margin-bottom:18px;">';
foreach ($navLinks as $act => $label) {
    $cls = ($act === 'dashboard') ? 'btn-primary' : 'btn-default';
    echo ' <a class="btn ' . $cls . '" href="addonmodules.php?module=clientverification&action=' . Sanitizer::escape($act) . '">' . Sanitizer::escape($label) . '</a>';
}
echo '</div>';

echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;">';
echo cv_card($_LANG['cv_total'], $stats['total'], '#3b82f6');
echo cv_card($_LANG['cv_pending'], $stats['pending'], '#f59e0b');
echo cv_card($_LANG['cv_under_review'], $stats['under_review'], '#8b5cf6');
echo cv_card($_LANG['cv_approved'], $stats['approved'], '#10b981');
echo cv_card($_LANG['cv_rejected'], $stats['rejected'], '#ef4444');
echo cv_card($_LANG['cv_expired'], $stats['expired'], '#6b7280');
echo '</div>';

echo '<h3>Provider Metrics</h3>';
echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
echo cv_card($_LANG['cv_didit_verified'], $diditApproved, '#06b6d4');
echo cv_card($_LANG['cv_manual_verified'], $manualApproved, '#14b8a6');
echo cv_card($_LANG['cv_manual_reviews'], $manualReviews, '#8b5cf6');
echo cv_card($_LANG['cv_provider_errors'], $providerErrors, '#ef4444');
echo '</div>';

echo '<p style="margin-top:20px;"><a class="btn btn-default" href="addonmodules.php?module=clientverification&action=verifications">'
    . Sanitizer::escape($_LANG['cv_verifications']) . '</a></p>';
