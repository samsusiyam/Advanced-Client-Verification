<?php

use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

$adminId = (int) ($_SESSION['adminid'] ?? 0);
$successMessage = '';
$warningMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cv_save_settings'])) {
    if (!Csrf::check($_POST['cv_token'] ?? null)) {
        $errorMessage = 'Security token expired or invalid. Please try again.';
    } else {
        $fields = [
            'enabled',
            'verification_mode',
            'didit_api_key',
            'didit_workflow_id',
            'didit_webhook_secret',
            'didit_auto_approve',
            'didit_on_error',
            'storage_path',
            'storage_encryption',
            'encryption_key',
            'max_file_size',
            'allowed_extensions',
            'verification_expiry_days',
            'risk_threshold_approve',
            'risk_threshold_review',
            'rate_limit_attempts',
            'webhook_outbound_secret',
            'api_token',
        ];

        foreach ($fields as $f) {
            $val = $_POST[$f] ?? '';
            
            // Handle checkboxes
            if (in_array($f, ['didit_auto_approve', 'storage_encryption'], true)) {
                $val = isset($_POST[$f]) ? '1' : '0';
            } elseif ($f === 'enabled') {
                $val = isset($_POST[$f]) ? 'yes' : 'no';
            }

            // Clean string values
            $cleanVal = Sanitizer::cleanString((string)$val, 500);
            
            // If it's a password field and left blank, keep existing
            $isPassword = in_array($f, ['didit_api_key', 'didit_webhook_secret', 'encryption_key', 'webhook_outbound_secret', 'api_token'], true);
            if ($isPassword && empty($val) && cv_setting($f, '') !== '') {
                continue;
            }

            cv_setting_set($f, $cleanVal);
        }

        // Storage path validation
        $sp = cv_setting('storage_path', '');
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if (!empty($sp) && $docRoot !== '' && strpos($sp, $docRoot) === 0) {
            $warningMessage = 'Warning: The document storage path is inside your web root (' . htmlspecialchars($docRoot) . '). For maximum security, KYC documents should be stored in a directory outside public_html.';
        }

        if (function_exists('cv_log_audit')) {
            cv_log_audit(0, 'settings_updated', $adminId, 'Settings updated by admin');
        }
        $successMessage = 'Settings have been successfully saved.';
    }
}

$activeSubTab = $_GET['tab'] ?? 'general';

cv_admin_header('settings', 'Module Settings', 'Configure verification modes, Didit automation, document storage, and risk parameters.');

?>

<style>
.cv-settings-container {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    overflow: hidden;
    margin-bottom: 30px;
}
.cv-tab-nav {
    display: flex;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0 10px;
    gap: 4px;
    overflow-x: auto;
}
.cv-tab-btn {
    padding: 12px 18px;
    font-size: 14px;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.cv-tab-btn:hover {
    color: #2563eb;
    text-decoration: none;
}
.cv-tab-btn.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
    background: #ffffff;
}
.cv-tab-content {
    padding: 24px;
}
.cv-form-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f1f5f9;
}
.cv-form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.cv-section-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cv-section-desc {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 16px;
}
.cv-form-group {
    margin-bottom: 18px;
}
.cv-form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
.cv-form-hint {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}
.cv-input-group {
    display: flex;
    gap: 8px;
}
.cv-copy-btn {
    white-space: nowrap;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
}
.cv-copy-btn:hover {
    background: #e2e8f0;
}
</style>

<?php if ($successMessage): ?>
    <div class="alert alert-success" style="border-radius: 6px; margin-bottom: 18px;">
        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?>
    </div>
<?php endif; ?>

<?php if ($warningMessage): ?>
    <div class="alert alert-warning" style="border-radius: 6px; margin-bottom: 18px;">
        <i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($warningMessage); ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger" style="border-radius: 6px; margin-bottom: 18px;">
        <i class="fa fa-times-circle"></i> <?php echo htmlspecialchars($errorMessage); ?>
    </div>
<?php endif; ?>

<form method="post" action="addonmodules.php?module=clientverification&action=settings&tab=<?php echo urlencode($activeSubTab); ?>">
<?php echo Csrf::field(); ?>
<input type="hidden" name="cv_save_settings" value="1">

<div class="cv-settings-container">
    <div class="cv-tab-nav">
        <a href="addonmodules.php?module=clientverification&action=settings&tab=general" class="cv-tab-btn <?php echo $activeSubTab === 'general' ? 'active' : ''; ?>">
            <i class="fa fa-sliders"></i> General
        </a>
        <a href="addonmodules.php?module=clientverification&action=settings&tab=didit" class="cv-tab-btn <?php echo $activeSubTab === 'didit' ? 'active' : ''; ?>">
            <i class="fa fa-id-badge"></i> Didit Automation
        </a>
        <a href="addonmodules.php?module=clientverification&action=settings&tab=storage" class="cv-tab-btn <?php echo $activeSubTab === 'storage' ? 'active' : ''; ?>">
            <i class="fa fa-lock"></i> Storage & Security
        </a>
        <a href="addonmodules.php?module=clientverification&action=settings&tab=risk" class="cv-tab-btn <?php echo $activeSubTab === 'risk' ? 'active' : ''; ?>">
            <i class="fa fa-shield"></i> Risk Engine
        </a>
        <a href="addonmodules.php?module=clientverification&action=settings&tab=api" class="cv-tab-btn <?php echo $activeSubTab === 'api' ? 'active' : ''; ?>">
            <i class="fa fa-exchange"></i> Webhooks & API
        </a>
    </div>

    <div class="cv-tab-content">
        <!-- GENERAL TAB -->
        <div style="<?php echo $activeSubTab === 'general' ? 'display:block;' : 'display:none;'; ?>">
            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-power-off text-primary"></i> Module Status</div>
                <div class="cv-section-desc">Enable or disable client verification system-wide.</div>
                
                <div class="cv-form-group">
                    <label style="font-size: 14px; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="enabled" value="yes" <?php echo cv_setting('enabled', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        Enable Client Verification Module
                    </label>
                    <div class="cv-form-hint">When disabled, checkout enforcement and client verification prompts are bypassed.</div>
                </div>
            </div>

            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-cogs text-primary"></i> Verification Workflow</div>
                <div class="cv-section-desc">Choose how client identity checks are processed by default.</div>

                <div class="row">
                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Default Verification Mode</label>
                        <select name="verification_mode" class="form-control">
                            <option value="hybrid" <?php echo cv_setting('verification_mode', 'hybrid') === 'hybrid' ? 'selected' : ''; ?>>Hybrid (Automated + Admin Fallback - Recommended)</option>
                            <option value="manual" <?php echo cv_setting('verification_mode', 'hybrid') === 'manual' ? 'selected' : ''; ?>>Manual Only (Admin reviews all documents)</option>
                            <option value="didit" <?php echo cv_setting('verification_mode', 'hybrid') === 'didit' ? 'selected' : ''; ?>>Didit Automated Only (Instant AI KYC)</option>
                        </select>
                        <div class="cv-form-hint">Hybrid attempts automated verification first and routes edge-cases to staff.</div>
                    </div>

                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Verification Expiry (Days)</label>
                        <input type="number" name="verification_expiry_days" class="form-control" value="<?php echo htmlspecialchars(cv_setting('verification_expiry_days', '365')); ?>" min="1" max="3650">
                        <div class="cv-form-hint">Approved verification validity period (default: 365 days / 1 year).</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Rate Limiting: Max Attempts / Hour</label>
                        <input type="number" name="rate_limit_attempts" class="form-control" value="<?php echo htmlspecialchars(cv_setting('rate_limit_attempts', '5')); ?>" min="1" max="50">
                        <div class="cv-form-hint">Maximum verification attempts allowed per client / IP per hour.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- DIDIT AUTOMATION TAB -->
        <div style="<?php echo $activeSubTab === 'didit' ? 'display:block;' : 'display:none;'; ?>">
            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-key text-primary"></i> Didit API Credentials</div>
                <div class="cv-section-desc">Connect your Didit automated KYC verification account.</div>

                <div class="row">
                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Didit API Key</label>
                        <input type="password" name="didit_api_key" class="form-control" placeholder="<?php echo cv_setting('didit_api_key', '') ? '••••••••••••••••' : 'Enter Didit API Key'; ?>" autocomplete="new-password">
                        <div class="cv-form-hint">Found in your Didit Dashboard under Developers / API Keys.</div>
                    </div>

                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Didit Workflow ID</label>
                        <input type="text" name="didit_workflow_id" class="form-control" value="<?php echo htmlspecialchars(cv_setting('didit_workflow_id', '')); ?>" placeholder="e.g., wf_xxxxxxxxxxxx">
                        <div class="cv-form-hint">The workflow identifier configured in your Didit portal.</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Didit Webhook Secret</label>
                        <input type="password" name="didit_webhook_secret" class="form-control" placeholder="<?php echo cv_setting('didit_webhook_secret', '') ? '••••••••••••••••' : 'Enter Webhook Signing Secret'; ?>" autocomplete="new-password">
                        <div class="cv-form-hint">Used to cryptographically verify incoming webhook authenticity.</div>
                    </div>

                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">On Didit Provider Error</label>
                        <select name="didit_on_error" class="form-control">
                            <option value="manual_review" <?php echo cv_setting('didit_on_error', 'manual_review') === 'manual_review' ? 'selected' : ''; ?>>Route to Manual Review (Recommended)</option>
                            <option value="reject" <?php echo cv_setting('didit_on_error', 'manual_review') === 'reject' ? 'selected' : ''; ?>>Reject Immediately</option>
                        </select>
                        <div class="cv-form-hint">Action taken if the provider is temporarily unavailable or returns an error.</div>
                    </div>
                </div>

                <div class="cv-form-group">
                    <label style="font-size: 14px; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="didit_auto_approve" value="1" <?php echo in_array(cv_setting('didit_auto_approve', '1'), ['1', 'yes', 'on'], true) ? 'checked' : ''; ?>>
                        Auto-Approve Client on Didit Success
                    </label>
                    <div class="cv-form-hint">When Didit successfully validates identity and risk score is low, automatically set status to Approved.</div>
                </div>
            </div>

            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-link text-primary"></i> Inbound Webhook URL</div>
                <div class="cv-section-desc">Copy this callback URL into your Didit Dashboard &gt; Webhooks settings:</div>

                <div class="cv-input-group" style="max-width: 700px;">
                    <input type="text" id="cv_webhook_url" class="form-control" value="<?php echo htmlspecialchars(cv_callback_url()); ?>" readonly style="background: #f8fafc; font-family: monospace;">
                    <button type="button" class="cv-copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('cv_webhook_url').value); alert('Webhook URL copied to clipboard!');">
                        <i class="fa fa-copy"></i> Copy URL
                    </button>
                </div>
            </div>
        </div>

        <!-- STORAGE & SECURITY TAB -->
        <div style="<?php echo $activeSubTab === 'storage' ? 'display:block;' : 'display:none;'; ?>">
            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-folder-open text-primary"></i> Document Storage Path</div>
                <div class="cv-section-desc">Configure where uploaded client identity documents and selfies are securely stored.</div>

                <div class="cv-form-group">
                    <label class="cv-form-label">Absolute Storage Path (outside public_html)</label>
                    <input type="text" name="storage_path" class="form-control" value="<?php echo htmlspecialchars(cv_setting('storage_path', '')); ?>" placeholder="/home/username/kyc-storage">
                    <div class="cv-form-hint">Example: <code>/home/cpanelusername/kyc_documents</code>. Leave empty to use module storage directory.</div>
                </div>
            </div>

            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-lock text-primary"></i> Document Encryption at Rest</div>
                <div class="cv-section-desc">Encrypt client documents with AES-256 before writing to disk.</div>

                <div class="cv-form-group">
                    <label style="font-size: 14px; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="storage_encryption" value="1" <?php echo in_array(cv_setting('storage_encryption', '0'), ['1', 'yes', 'on'], true) ? 'checked' : ''; ?>>
                        Enable Document Encryption (AES-256-CBC)
                    </label>
                    <div class="cv-form-hint">Documents are decrypted on the fly when viewed by authorized admins.</div>
                </div>

                <div class="row">
                    <div class="col-md-8 cv-form-group">
                        <label class="cv-form-label">Custom Encryption Key</label>
                        <div class="cv-input-group">
                            <input type="password" id="encryption_key_field" name="encryption_key" class="form-control" placeholder="<?php echo cv_setting('encryption_key', '') ? '••••••••••••••••' : 'Enter 32-character key'; ?>" autocomplete="new-password">
                            <button type="button" class="cv-copy-btn" onclick="var k=Array.from(crypto.getRandomValues(new Uint8Array(24))).map(b=>b.toString(16).padStart(2,'0')).join(''); document.getElementById('encryption_key_field').value=k; document.getElementById('encryption_key_field').type='text';">
                                <i class="fa fa-magic"></i> Generate Key
                            </button>
                        </div>
                        <div class="cv-form-hint">Leave blank to use default derived application key.</div>
                    </div>
                </div>
            </div>

            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-file text-primary"></i> Upload Restrictions</div>
                <div class="cv-section-desc">Define upload limitations for manual verification document uploads.</div>

                <div class="row">
                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Max File Size (MB)</label>
                        <input type="number" name="max_file_size" class="form-control" value="<?php echo htmlspecialchars(cv_setting('max_file_size', '10')); ?>" min="1" max="100">
                        <div class="cv-form-hint">Maximum file size per uploaded document (default: 10 MB).</div>
                    </div>

                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Allowed File Extensions</label>
                        <input type="text" name="allowed_extensions" class="form-control" value="<?php echo htmlspecialchars(cv_setting('allowed_extensions', 'pdf,jpg,jpeg,png,webp')); ?>">
                        <div class="cv-form-hint">Comma-separated list (e.g., pdf,jpg,jpeg,png,webp).</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RISK ENGINE TAB -->
        <div style="<?php echo $activeSubTab === 'risk' ? 'display:block;' : 'display:none;'; ?>">
            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-tachometer text-primary"></i> Risk Scoring &amp; Decision Engine</div>
                <div class="cv-section-desc">Automated risk evaluation thresholds (0-100 scale).</div>

                <div class="row">
                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Auto-Approve Risk Threshold (0-100)</label>
                        <input type="number" name="risk_threshold_approve" class="form-control" value="<?php echo htmlspecialchars(cv_setting('risk_threshold_approve', '30')); ?>" min="0" max="100">
                        <div class="cv-form-hint">Risk score <strong>below or equal</strong> to this value auto-approves (default: 30).</div>
                    </div>

                    <div class="col-md-6 cv-form-group">
                        <label class="cv-form-label">Manual Review Risk Threshold (0-100)</label>
                        <input type="number" name="risk_threshold_review" class="form-control" value="<?php echo htmlspecialchars(cv_setting('risk_threshold_review', '70')); ?>" min="0" max="100">
                        <div class="cv-form-hint">Risk score between Approve and Review requires manual staff review. Above this value rejects.</div>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-top: 10px;">
                    <div style="font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">Decision Breakdown:</div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <span class="label label-success" style="padding: 6px 12px; font-size: 12px;">0 - 30: Low Risk &rarr; Auto-Approve</span>
                        <span class="label label-warning" style="padding: 6px 12px; font-size: 12px;">31 - 70: Medium Risk &rarr; Staff Review</span>
                        <span class="label label-danger" style="padding: 6px 12px; font-size: 12px;">71 - 100: High Risk &rarr; Auto-Reject</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- API & WEBHOOKS TAB -->
        <div style="<?php echo $activeSubTab === 'api' ? 'display:block;' : 'display:none;'; ?>">
            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-paper-plane text-primary"></i> Outbound Webhook Security</div>
                <div class="cv-section-desc">Secret used for signing outgoing webhook notifications sent to third-party endpoints.</div>

                <div class="cv-form-group">
                    <label class="cv-form-label">Outbound Webhook Secret</label>
                    <input type="password" name="webhook_outbound_secret" class="form-control" placeholder="<?php echo cv_setting('webhook_outbound_secret', '') ? '••••••••••••••••' : 'Enter Secret Key'; ?>" autocomplete="new-password" style="max-width: 600px;">
                    <div class="cv-form-hint">Included in the <code>X-Signature-256</code> header for outbound events.</div>
                </div>
            </div>

            <div class="cv-form-section">
                <div class="cv-section-title"><i class="fa fa-key text-primary"></i> Global Master API Token</div>
                <div class="cv-section-desc">Master token for external system integrations and automation.</div>

                <div class="cv-form-group">
                    <label class="cv-form-label">API Access Token</label>
                    <input type="password" name="api_token" class="form-control" placeholder="<?php echo cv_setting('api_token', '') ? '••••••••••••••••' : 'Enter API Access Token'; ?>" autocomplete="new-password" style="max-width: 600px;">
                    <div class="cv-form-hint">You can also generate scoped, granular tokens in the <a href="addonmodules.php?module=clientverification&action=api">API Tokens</a> menu.</div>
                </div>
            </div>
        </div>
    </div>

    <div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 24px; display: flex; justify-content: flex-end; gap: 10px;">
        <a href="addonmodules.php?module=clientverification&action=dashboard" class="btn btn-default">Cancel</a>
        <button type="submit" class="btn btn-primary" style="padding: 7px 24px; font-weight: 600;">
            <i class="fa fa-save"></i> Save Settings
        </button>
    </div>
</div>
</form>

