<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;
use ClientVerification\Services\VerificationService;
use ClientVerification\Storage\DocumentStorage;

$adminId = (int) $_SESSION['adminid'];

// Secure document download (admin-gated by clientverification_output).
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $doc = Capsule::table('mod_cv_documents')->where('id', (int) $_GET['download'])->first();
    if ($doc) {
        $config = cv_get_config();
        $storage = new DocumentStorage(
            $config['storage_path'] ?? '',
            (bool) ($config['storage_encryption'] ?? false),
            $config['encryption_key'] ?? ''
        );
        $content = $storage->read($doc->storage_path, (bool) $doc->encrypted);
        if ($content !== null) {
            header('Content-Type: ' . Sanitizer::headerValue($doc->mime_type));
            header('Content-Disposition: inline; filename="' . Sanitizer::headerValue($doc->original_filename) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        }
    }
    http_response_code(404);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);

// Handle POST actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && Csrf::check($_POST['cv_token'] ?? null)) {
    $action = $_POST['action'];
    switch ($action) {
        case 'approve':
            VerificationService::updateStatus($id, 'approved', $adminId, 'admin_approved');
            break;
        case 'reject':
            VerificationService::updateStatus($id, 'rejected', $adminId, $_POST['note'] ?? 'admin_rejected');
            break;
        case 'request_info':
            VerificationService::requestInformation($id, $adminId, $_POST['note'] ?? '');
            break;
        case 'suspend':
            VerificationService::suspend($id, $adminId, $_POST['note'] ?? '');
            break;
        case 'manual_review':
            Capsule::table('mod_cv_verifications')->where('id', $id)->update(['status' => 'under_review', 'manual_review_required' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            cv_log_audit($id, 'manual_review', $adminId, '');
            break;
    }
    echo '<div class="alert alert-success">Action applied.</div>';
}

$row = VerificationService::find($id);
if (!$row) {
    echo '<div class="alert alert-danger">Verification not found.</div>';
    return;
}

$personal = Capsule::table('mod_cv_personal_data')->where('verification_id', $id)->first();
$documents = Capsule::table('mod_cv_documents')->where('verification_id', $id)->get();
$client = Capsule::table('tblclients')->where('id', $row->client_id)->first();
$audit = json_decode($row->audit_log ?? '[]', true);

echo '<h2>' . Sanitizer::escape($_LANG['cv_verification']) . ' #' . Sanitizer::escape($id) . '</h2>';

echo '<div style="display:flex;gap:20px;flex-wrap:wrap;">';
echo '<div style="flex:1;min-width:300px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">';
echo '<h4>' . Sanitizer::escape($_LANG['cv_client']) . '</h4>';
echo 'Name: ' . Sanitizer::escape($client->firstname ?? '') . ' ' . Sanitizer::escape($client->lastname ?? '') . '<br>';
echo 'Email: ' . Sanitizer::escape($client->email ?? '') . '<br>';
echo 'Country: ' . Sanitizer::escape($client->country ?? '') . '<br><br>';
echo '<strong>' . Sanitizer::escape($_LANG['cv_method']) . ':</strong> ' . Sanitizer::escape($row->verification_method) . '<br>';
echo '<strong>Didit Status:</strong> ' . Sanitizer::escape($row->didit_status ?? '-') . '<br>';
echo '<strong>Didit Decision:</strong> ' . Sanitizer::escape($row->didit_decision ?? '-') . '<br>';
echo '<strong>' . Sanitizer::escape($_LANG['cv_risk']) . ':</strong> ' . Sanitizer::escape($row->risk_level) . ' (' . Sanitizer::escape($row->risk_score) . ')<br>';
echo '</div>';

echo '<div style="flex:1;min-width:300px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">';
echo '<h4>' . Sanitizer::escape($_LANG['cv_personal_info']) . '</h4>';
if ($personal) {
    foreach (['first_name', 'last_name', 'date_of_birth', 'phone', 'address', 'city', 'state', 'postal_code', 'country'] as $f) {
        echo Sanitizer::escape(ucfirst(str_replace('_', ' ', $f))) . ': ' . Sanitizer::escape($personal->$f ?? '') . '<br>';
    }
} else {
    echo 'No personal data.';
}
echo '</div>';
echo '</div>';

echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-top:16px;">';
echo '<h4>' . Sanitizer::escape($_LANG['cv_documents']) . '</h4>';
if ($documents->isEmpty()) {
    echo 'No documents uploaded.';
} else {
    foreach ($documents as $doc) {
        echo '- ' . Sanitizer::escape($doc->document_type) . ' (' . Sanitizer::escape($doc->side ?? '') . '): '
            . '<a href="addonmodules.php?module=clientverification&action=verification&id=' . Sanitizer::escape($id) . '&download=' . Sanitizer::escape($doc->id) . '" target="_blank">View</a>'
            . ' [' . Sanitizer::escape($doc->status) . ']<br>';
    }
}
echo '</div>';

echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-top:16px;">';
echo '<h4>' . Sanitizer::escape($_LANG['cv_audit_timeline']) . '</h4>';
if (!empty($audit)) {
    foreach (array_reverse($audit) as $entry) {
        echo Sanitizer::escape($entry['ts'] ?? '') . ' - ' . Sanitizer::escape($entry['action'] ?? '') . '<br>';
    }
} else {
    echo 'No audit entries.';
}
echo '</div>';

echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-top:16px;">';
echo '<h4>Actions</h4>';
echo '<form method="post">';
echo Csrf::field();
echo '<textarea name="note" class="form-control" placeholder="' . Sanitizer::escape($_LANG['cv_admin_notes']) . '" style="width:100%;"></textarea><br>';
echo '<button name="action" value="approve" class="btn btn-success">' . Sanitizer::escape($_LANG['cv_approve']) . '</button> ';
echo '<button name="action" value="reject" class="btn btn-danger">' . Sanitizer::escape($_LANG['cv_reject']) . '</button> ';
echo '<button name="action" value="request_info" class="btn btn-warning">' . Sanitizer::escape($_LANG['cv_request_info']) . '</button> ';
echo '<button name="action" value="manual_review" class="btn btn-info">' . Sanitizer::escape($_LANG['cv_manual_review']) . '</button> ';
echo '<button name="action" value="suspend" class="btn btn-default">' . Sanitizer::escape($_LANG['cv_suspend']) . '</button>';
echo '</form>';
echo '</div>';
