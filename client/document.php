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
                header('Content-Length: ' . strlen($content));
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

    $types = Capsule::table('mod_cv_document_types')->get();
    $allowedTypes = [];
    foreach ($types as $t) {
        $allowedTypes[$t->name] = $t;
    }
    $docType = Sanitizer::alphanumeric($_POST['document_type'] ?? 'national_id');
    $docNumber = Sanitizer::text($_POST['document_number'] ?? '');

    // Save document number in personal data or audit log
    if (!empty($docNumber)) {
        try {
            $hasPersonal = Capsule::table('mod_cv_personal_data')->where('verification_id', $verificationId)->exists();
            if ($hasPersonal) {
                // If column doesn't exist, ignore exception
                if (Capsule::schema()->hasColumn('mod_cv_personal_data', 'document_number')) {
                    Capsule::table('mod_cv_personal_data')->where('verification_id', $verificationId)->update([
                        'document_number' => $docNumber,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {}
        cv_log_audit($verificationId, 'document_number_saved', 0, 'number=' . substr($docNumber, 0, 4) . '****');
    }

    $uploadQueue = [];

    // 1. Process new form format (doc_front, doc_back, doc_selfie)
    if (isset($_FILES['doc_front']) && is_array($_FILES['doc_front']) && ($_FILES['doc_front']['error'] ?? null) === UPLOAD_ERR_OK) {
        $uploadQueue[] = ['file' => $_FILES['doc_front'], 'type' => $docType, 'side' => 'front'];
    }
    if (isset($_FILES['doc_back']) && is_array($_FILES['doc_back']) && ($_FILES['doc_back']['error'] ?? null) === UPLOAD_ERR_OK) {
        $uploadQueue[] = ['file' => $_FILES['doc_back'], 'type' => $docType, 'side' => 'back'];
    }
    if (isset($_FILES['doc_selfie']) && is_array($_FILES['doc_selfie']) && ($_FILES['doc_selfie']['error'] ?? null) === UPLOAD_ERR_OK) {
        $uploadQueue[] = ['file' => $_FILES['doc_selfie'], 'type' => 'selfie', 'side' => 'front'];
    }

    // 2. Process any legacy field patterns (doc_{typename}__front / back)
    foreach ($_FILES as $field => $file) {
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            continue;
        }
        if (in_array($field, ['doc_front', 'doc_back', 'doc_selfie'])) {
            continue;
        }
        if (preg_match('/^doc_(.+?)(?:__(front|back))?$/', (string) $field, $m)) {
            $uploadQueue[] = ['file' => $file, 'type' => $m[1], 'side' => $m[2] ?? 'front'];
        }
    }

    foreach ($uploadQueue as $item) {
        $file = $item['file'];
        $typeName = $item['type'];
        $side = $item['side'];
        $label = $allowedTypes[$typeName]->label ?? ucfirst(str_replace('_', ' ', $typeName));

        $check = $validator->validate($file['tmp_name'], $file['name']);
        if (!$check['success']) {
            echo '<div class="alert alert-danger" style="max-width: 600px; margin: 15px auto;">' . Sanitizer::escape($label) . ' (' . Sanitizer::escape($side) . '): ' . Sanitizer::escape($check['error']) . '</div>';
            continue;
        }
        $stored = $storage->store($file['tmp_name'], $verificationId, $check['extension']);
        if (!$stored['success']) {
            echo '<div class="alert alert-danger" style="max-width: 600px; margin: 15px auto;">' . Sanitizer::escape($label) . ' (' . Sanitizer::escape($side) . '): ' . Sanitizer::escape($stored['error']) . '</div>';
            continue;
        }
        $ext = strtolower(ltrim($check['extension'] ?? 'jpg', '.'));
        $randHash = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        $cleanTypeName = strtoupper(str_replace([' ', '-'], '_', $typeName));
        $cleanSide = strtoupper($side ?: 'DOC');
        $safeDisplayName = "KYC_{$verificationId}_{$cleanTypeName}_{$cleanSide}_{$randHash}.{$ext}";

        Capsule::table('mod_cv_documents')->insert([
            'verification_id' => $verificationId,
            'document_type' => $typeName,
            'side' => $side,
            'original_filename' => $safeDisplayName,
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
        Capsule::table('mod_cv_verifications')->where('id', $verificationId)->update([
            'status' => 'under_review',
            'manual_review_required' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        cv_log_audit($verificationId, 'documents_submitted', 0, 'Documents uploaded by client');
        
        echo '<div style="max-width: 600px; margin: 30px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px 24px; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
            <div style="width: 64px; height: 64px; background: #dcfce7; color: #16a34a; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 28px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #166534;">Documents Uploaded Successfully</h3>
            <p style="color: #4b5563; font-size: 14px; margin-bottom: 24px;">Your files have been received securely and submitted for compliance verification.</p>
            <a class="btn btn-primary" href="index.php?m=clientverification&action=verification&id=' . Sanitizer::escape($verificationId) . '" style="font-weight: 600; padding: 10px 24px;">
                <i class="fa fa-arrow-left"></i> Return to Verification Status
            </a>
        </div>';
        return;
    } else {
        echo '<div class="alert alert-warning" style="max-width: 600px; margin: 20px auto; border-radius: 8px;">No valid documents were selected for upload. <a href="index.php?m=clientverification&action=verification&id=' . Sanitizer::escape($verificationId) . '">Go Back</a></div>';
    }
}

