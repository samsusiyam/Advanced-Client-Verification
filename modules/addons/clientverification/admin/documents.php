<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$statusFilter = $_GET['status'] ?? '';
$query = Capsule::table('mod_cv_documents')
    ->leftJoin('mod_cv_verifications', 'mod_cv_documents.verification_id', '=', 'mod_cv_verifications.id')
    ->leftJoin('tblclients', 'mod_cv_verifications.client_id', '=', 'tblclients.id')
    ->select('mod_cv_documents.*', 'mod_cv_verifications.status as vstatus', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.id as client_id');
if ($statusFilter) {
    $query->where('mod_cv_documents.status', $statusFilter);
}
$docs = $query->orderByDesc('mod_cv_documents.id')->limit(100)->get();

echo '<h2>' . Sanitizer::escape($_LANG['cv_documents_mgmt']) . '</h2>';

echo '<div style="margin-bottom:12px;">';
foreach (['', 'pending', 'approved', 'rejected'] as $s) {
    $label = $s ?: 'all';
    $cls = ($statusFilter === $s) ? 'btn-primary' : 'btn-default';
    echo ' <a class="btn ' . $cls . '" href="addonmodules.php?module=clientverification&action=documents&status=' . Sanitizer::escape($s) . '">' . Sanitizer::escape(ucfirst(str_replace('_', ' ', $label))) . '</a>';
}
echo '</div>';

echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">';
echo '<table class="table table-bordered" style="width:100%;">';
echo '<thead><tr><th>ID</th><th>' . Sanitizer::escape($_LANG['cv_documents_client']) . '</th><th>' . Sanitizer::escape($_LANG['cv_documents_type']) . '</th><th>Side</th><th>' . Sanitizer::escape($_LANG['cv_documents_status']) . '</th><th>Uploaded</th><th>Verification</th><th>View</th></tr></thead><tbody>';
foreach ($docs as $d) {
    $clientName = ($d->firstname ?? '') . ' ' . ($d->lastname ?? '');
    echo '<tr>';
    echo '<td>' . Sanitizer::escape($d->id) . '</td>';
    echo '<td>' . Sanitizer::escape(trim($clientName)) . ' (#' . Sanitizer::escape($d->client_id ?? '?') . ')</td>';
    echo '<td>' . Sanitizer::escape($d->document_type) . '</td>';
    echo '<td>' . Sanitizer::escape($d->side ?? '-') . '</td>';
    echo '<td>' . Sanitizer::escape($d->status) . '</td>';
    echo '<td>' . Sanitizer::escape($d->created_at) . '</td>';
    echo '<td><a class="btn btn-default btn-sm" href="addonmodules.php?module=clientverification&action=verification&id=' . Sanitizer::escape($d->verification_id) . '">#' . Sanitizer::escape($d->verification_id) . '</a></td>';
    echo '<td><a class="btn btn-primary btn-sm" href="addonmodules.php?module=clientverification&action=verification&id=' . Sanitizer::escape($d->verification_id) . '&download=' . Sanitizer::escape($d->id) . '" target="_blank">View</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
