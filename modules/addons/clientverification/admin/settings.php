<?php

use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['cv_token']) {
    if (Csrf::check($_POST['cv_token'])) {
        $fields = ['verification_mode', 'risk_threshold_approve', 'risk_threshold_review', 'max_file_size', 'allowed_extensions', 'verification_expiry_days', 'storage_encryption'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                cv_setting_set($f, Sanitizer::cleanString($_POST[$f], 500));
            }
        }
        if (isset($_POST['storage_path'])) {
            $sp = rtrim(Sanitizer::cleanString($_POST['storage_path'], 500), '/');
            cv_setting_set('storage_path', $sp);
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
            if ($docRoot !== '' && strpos($sp, $docRoot) === 0) {
                echo '<div class="alert alert-warning">Warning: the document storage path is inside the web root. Store documents outside public_html for security.</div>';
            }
        }
        echo '<div class="alert alert-success">Settings saved.</div>';
    }
}

?>
<h2><?php echo Sanitizer::escape($_LANG['cv_settings']); ?></h2>
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;max-width:700px;">
<form method="post">
<?php echo Csrf::field(); ?>
<div class="form-group">
    <label>Verification Mode</label>
    <select name="verification_mode" class="form-control">
        <?php foreach (['hybrid' => 'Hybrid', 'manual' => 'Manual', 'didit' => 'Didit'] as $k => $v): ?>
            <option value="<?php echo Sanitizer::escape($k); ?>" <?php echo cv_setting('verification_mode', 'hybrid') === $k ? 'selected' : ''; ?>><?php echo Sanitizer::escape($v); ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="form-group">
    <label>Document Storage Path (outside public_html)</label>
    <input type="text" name="storage_path" class="form-control" value="<?php echo Sanitizer::escape(cv_setting('storage_path', '')); ?>">
</div>
<div class="form-group">
    <label>Max File Size (MB)</label>
    <input type="text" name="max_file_size" class="form-control" value="<?php echo Sanitizer::escape(cv_setting('max_file_size', '10')); ?>">
</div>
<div class="form-group">
    <label>Allowed Extensions (comma separated)</label>
    <input type="text" name="allowed_extensions" class="form-control" value="<?php echo Sanitizer::escape(cv_setting('allowed_extensions', 'pdf,jpg,jpeg,png,webp')); ?>">
</div>
<div class="form-group">
    <label>Verification Expiry (days)</label>
    <input type="text" name="verification_expiry_days" class="form-control" value="<?php echo Sanitizer::escape(cv_setting('verification_expiry_days', '365')); ?>">
</div>
<div class="form-group">
    <label>Auto-Approve Risk Threshold (0-100)</label>
    <input type="text" name="risk_threshold_approve" class="form-control" value="<?php echo Sanitizer::escape(cv_setting('risk_threshold_approve', '30')); ?>">
</div>
<div class="form-group">
    <label>Manual Review Risk Threshold (0-100)</label>
    <input type="text" name="risk_threshold_review" class="form-control" value="<?php echo Sanitizer::escape(cv_setting('risk_threshold_review', '70')); ?>">
</div>
<div class="form-group">
    <label><input type="checkbox" name="storage_encryption" value="1" <?php echo cv_setting('storage_encryption', '0') === '1' ? 'checked' : ''; ?>> Enable Document Encryption</label>
</div>
<p><em>API credentials (Didit API Key, Workflow ID, Webhook Secret) are configured on the Addon Modules configuration page for this module.</em></p>
<button type="submit" class="btn btn-primary">Save</button>
</form>
</div>
