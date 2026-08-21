<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$id = (int) ($_GET['id'] ?? 0);
$v = Capsule::table('mod_cv_verifications')->where('id', $id)->where('client_id', $clientId)->first();

if (!$v) {
    echo '<div class="alert alert-danger">Verification not found.</div>';
    return;
}

if ($v->status === 'approved') {
    echo '<div style="text-align:center;color:#10b981;"><h3>&#10003; ' . Sanitizer::escape($_LANG['cv_approved_msg']) . '</h3></div>';
    return;
}

echo '<div style="max-width:700px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">';
echo '<h3>' . Sanitizer::escape($_LANG['cv_verification']) . ' #' . Sanitizer::escape($id) . '</h3>';
echo '<p>Status: <strong>' . Sanitizer::escape($v->status) . '</strong></p>';

if ($v->verification_method === 'manual' || $v->status === 'under_review' || $v->status === 'pending') {
    $types = Capsule::table('mod_cv_document_types')->where('is_required', 1)->get();
    echo '<h4>' . Sanitizer::escape($_LANG['cv_upload_document']) . '</h4>';
    echo '<form method="post" enctype="multipart/form-data" action="index.php?m=clientverification&action=document">';
    echo Csrf::field();
    echo '<input type="hidden" name="verification_id" value="' . Sanitizer::escape($id) . '">';
    foreach ($types as $t) {
        $sides = (int) ($t->sides_required ?? 1);
        if ($sides >= 2) {
            echo '<div class="form-group" style="margin-bottom:12px;">
                <label>' . Sanitizer::escape($t->label) . ' (Front)</label>
                <input type="file" name="doc_' . Sanitizer::escape($t->name) . '__front" class="form-control" required>
            </div>';
            echo '<div class="form-group" style="margin-bottom:12px;">
                <label>' . Sanitizer::escape($t->label) . ' (Back)</label>
                <input type="file" name="doc_' . Sanitizer::escape($t->name) . '__back" class="form-control" required>
            </div>';
        } else {
            echo '<div class="form-group" style="margin-bottom:12px;">
                <label>' . Sanitizer::escape($t->label) . '</label>
                <input type="file" name="doc_' . Sanitizer::escape($t->name) . '" class="form-control" required>
            </div>';
        }
    }
    echo '<button type="submit" class="btn btn-primary">Submit Documents</button>';
    echo '</form>';
}

$docs = Capsule::table('mod_cv_documents')->where('verification_id', $id)->get();
if (!$docs->isEmpty()) {
    echo '<h4>' . Sanitizer::escape($_LANG['cv_documents']) . '</h4><ul>';
    foreach ($docs as $d) {
        echo '<li>' . Sanitizer::escape($d->document_type) . ' - ' . Sanitizer::escape($d->status) . '</li>';
    }
    echo '</ul>';
}

echo '</div>';
