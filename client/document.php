<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;
use ClientVerification\Security\RateLimiter;
use ClientVerification\Validation\FileValidator;
use ClientVerification\Storage\DocumentStorage;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$config = cv_get_config();

// Secure download of own document.
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $doc = Capsule::table('mod_cv_documents')->where('id', (int) $_GET['download'])->first();
    if ($doc) {
        $v = Capsule::table('mod_cv_verifications')->where('id', $doc->verification_id)->where('client_id', $clientId)->first();
        if ($v) {
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
                echo $content;
                exit;
            }
        }
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['cv_token'] ?? null)) {
        echo '<div class="alert alert-danger">Security token invalid.</div>';
        return;
    }

    $verificationId = Sanitizer::int($_POST['verification_id'] ?? 0);
    $v = Capsule::table('mod_cv_verifications')->where('id', $verificationId)->where('client_id', $clientId)->first();
    if (!$v) {
        echo '<div class="alert alert-danger">Verification not found.</div>';
        return;
    }

    if (!RateLimiter::attempt('document_upload', 'client_' . $clientId, 20, 3600)) {
        echo '<div class="alert alert-danger">Upload rate limit exceeded.</div>';
        return;
    }

    $allowed = array_map('trim', explode(',', $config['allowed_extensions'] ?? 'pdf,jpg,jpeg,png,webp'));
    $maxBytes = (int) ($config['max_file_size'] ?? 10) * 1024 * 1024;
    $validator = new FileValidator($allowed, $maxBytes);
    $storage = new DocumentStorage(
        $config['storage_path'] ?? '',
        (bool) ($config['storage_encryption'] ?? false),
        $config['encryption_key'] ?? ''
    );
    $retentionDays = (int) ($config['document_retention_days'] ?? 365);

    $types = Capsule::table('mod_cv_document_types')->where('is_required', 1)->get();
    $allowedTypes = [];
    foreach ($types as $t) {
        $allowedTypes[$t->name] = $t;
    }
    $anyUploaded = false;

    foreach ($_FILES as $field => $file) {
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            continue;
        }
        if (!preg_match('/^doc_(.+?)(?:__(front|back))?$/', (string) $field, $m)) {
            continue;
        }
        $typeName = $m[1];
        $side = $m[2] ?? 'front';
        if (!isset($allowedTypes[$typeName])) {
            echo '<div class="alert alert-danger">Unknown document type: ' . Sanitizer::escape($typeName) . '</div>';
            continue;
        }
        $t = $allowedTypes[$typeName];
        $check = $validator->validate($file['tmp_name'], $file['name']);
        if (!$check['success']) {
            echo '<div class="alert alert-danger">' . Sanitizer::escape($t->label) . ' (' . Sanitizer::escape($side) . '): ' . Sanitizer::escape($check['error']) . '</div>';
            continue;
        }
        $stored = $storage->store($file['tmp_name'], $verificationId, $check['extension']);
        if (!$stored['success']) {
            echo '<div class="alert alert-danger">' . Sanitizer::escape($t->label) . ' (' . Sanitizer::escape($side) . '): ' . Sanitizer::escape($stored['error']) . '</div>';
            continue;
        }
        Capsule::table('mod_cv_documents')->insert([
            'verification_id' => $verificationId,
            'document_type' => $t->name,
            'side' => $side,
            'original_filename' => basename($file['name']),
            'stored_filename' => $stored['stored_filename'],
            'storage_path' => $stored['storage_path'],
            'mime_type' => $check['mime'],
            'file_size' => filesize($file['tmp_name']),
            'sha256_hash' => $stored['sha256'],
            'encrypted' => (bool) ($config['storage_encryption'] ?? false),
            'status' => 'pending',
            'expires_at' => date('Y-m-d H:i:s', time() + $retentionDays * 86400),
            'uploaded_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $anyUploaded = true;
    }

    if ($anyUploaded && $v) {
        Capsule::table('mod_cv_verifications')->where('id', $verificationId)->update(['status' => 'under_review', 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit($verificationId, 'documents_submitted', 0, '');
    }

    echo '<div class="alert alert-success">Documents submitted for review.</div>';
    echo '<a class="btn btn-default" href="index.php?m=clientverification&action=verification&id=' . Sanitizer::escape($verificationId) . '">Back</a>';
}
