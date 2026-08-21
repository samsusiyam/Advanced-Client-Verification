<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$statusFilter = $_GET['status'] ?? '';
$query = Capsule::table('mod_cv_verifications')->orderByDesc('id');
if ($statusFilter) {
    $query->where('status', $statusFilter);
}
$rows = $query->paginate(25);

echo '<h2>' . Sanitizer::escape($_LANG['cv_verifications']) . '</h2>';

echo '<div style="margin-bottom:12px;">';
foreach (['', 'pending', 'under_review', 'approved', 'rejected', 'expired'] as $s) {
    $label = $s ?: 'all';
    $cls = ($statusFilter === $s) ? 'btn-primary' : 'btn-default';
    echo ' <a class="btn ' . $cls . '" href="addonmodules.php?module=clientverification&action=verifications&status=' . Sanitizer::escape($s) . '">' . Sanitizer::escape(ucfirst($label)) . '</a>';
}
echo '</div>';

echo '<table class="table table-bordered" style="width:100%;background:#fff;">';
echo '<thead><tr>';
foreach (['ID', 'Client', 'Method', 'Status', 'Risk', 'Submitted', 'Action'] as $h) {
    echo '<th>' . Sanitizer::escape($h) . '</th>';
}
echo '</tr></thead><tbody>';

foreach ($rows as $row) {
    $client = Capsule::table('tblclients')->where('id', $row->client_id)->first();
    $clientName = $client ? ($client->firstname . ' ' . $client->lastname) : 'Unknown';
    echo '<tr>';
    echo '<td>' . Sanitizer::escape($row->id) . '</td>';
    echo '<td>' . Sanitizer::escape($clientName) . ' (#' . Sanitizer::escape($row->client_id) . ')</td>';
    echo '<td>' . Sanitizer::escape($row->verification_method) . '</td>';
    echo '<td>' . Sanitizer::escape($row->status) . '</td>';
    echo '<td>' . Sanitizer::escape($row->risk_level) . ' (' . Sanitizer::escape($row->risk_score) . ')</td>';
    echo '<td>' . Sanitizer::escape($row->submitted_at) . '</td>';
    echo '<td><a class="btn btn-default" href="addonmodules.php?module=clientverification&action=verification&id=' . Sanitizer::escape($row->id) . '">Review</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo $rows->links();
