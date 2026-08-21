<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$rows = Capsule::table('mod_cv_audit_logs')->orderByDesc('id')->paginate(50);

echo '<h2>' . Sanitizer::escape($_LANG['cv_audit_logs']) . '</h2>';
echo '<table class="table table-bordered" style="width:100%;background:#fff;">';
echo '<thead><tr><th>ID</th><th>Verification</th><th>Admin</th><th>Action</th><th>Note</th><th>IP</th><th>Time</th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . Sanitizer::escape($r->id) . '</td>';
    echo '<td>' . Sanitizer::escape($r->verification_id) . '</td>';
    echo '<td>' . Sanitizer::escape($r->admin_id) . '</td>';
    echo '<td>' . Sanitizer::escape($r->action) . '</td>';
    echo '<td>' . Sanitizer::escape($r->note) . '</td>';
    echo '<td>' . Sanitizer::escape($r->ip) . '</td>';
    echo '<td>' . Sanitizer::escape($r->created_at) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo $rows->links();
