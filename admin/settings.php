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
            'enable_didit',
            'enable_manual',
            'verification_expiry_days',
            'rate_limit_attempts',
            'audit_log_retention_days',
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
            'risk_threshold_approve',
            'risk_threshold_review',
            'mail_client_started',
            'mail_client_approved',
            'mail_client_rejected',
            'mail_client_under_review',
            'mail_client_info_requested',
            'mail_client_expiring',
            'mail_client_expired',
            'mail_admin_new_submission',
            'mail_admin_didit_completed',
            'mail_admin_high_risk',
            'mail_admin_info_response',
            'admin_notification_emails',
        ];

        foreach ($fields as $f) {
            $val = $_POST[$f] ?? '';
            
            // Checkbox handling
            if (in_array($f, ['didit_auto_approve', 'storage_encryption'], true)) {
                $val = isset($_POST[$f]) ? '1' : '0';
            } elseif (in_array($f, [
                'enabled', 'enable_didit', 'enable_manual',
                'mail_client_started', 'mail_client_approved', 'mail_client_rejected',
                'mail_client_under_review', 'mail_client_info_requested', 'mail_client_expiring', 'mail_client_expired',
                'mail_admin_new_submission', 'mail_admin_didit_completed', 'mail_admin_high_risk', 'mail_admin_info_response',
            ], true)) {
                $val = isset($_POST[$f]) ? 'yes' : 'no';
            } else {
                $val = trim((string)$val);
            }

            cv_setting_set($f, $val);
        }

        // Storage path security check
        $sp = cv_setting('storage_path', '');
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if (!empty($sp) && $docRoot !== '' && strpos($sp, $docRoot) === 0) {
            $warningMessage = 'Warning: Storage path is inside the web root. For maximum KYC compliance, choose a directory outside public_html.';
        }

        if (function_exists('cv_log_audit')) {
            cv_log_audit(0, 'settings_updated', $adminId, 'Module settings updated');
        }
        $successMessage = 'Settings saved successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cv_test_didit'])) {
    if (!Csrf::check($_POST['cv_token'] ?? null)) {
        $errorMessage = 'Security token expired or invalid. Please try again.';
    } else {
        $testApiKey = trim($_POST['didit_api_key'] ?? cv_setting('didit_api_key', ''));
        $testWf = trim($_POST['didit_workflow_id'] ?? cv_setting('didit_workflow_id', ''));
        $activeTab = 'didit';
        
        if (empty($testApiKey) || empty($testWf)) {
            $errorMessage = 'Please provide both Didit API Key and Workflow ID to test connection.';
        } else {
            $headers = [
                'x-api-key: ' . $testApiKey,
                'Authorization: Bearer ' . $testApiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ];
            $payload = [
                'workflow_id' => $testWf,
                'vendor_data' => 'test-probe-' . time(),
            ];
            $testRes = \ClientVerification\Helpers\Http::post('https://verification.didit.me/v3/session/', $payload, $headers);
            if (!$testRes['success'] && ($testRes['http_code'] === 404 || strpos($testRes['error'], '404') !== false)) {
                $testRes = \ClientVerification\Helpers\Http::post('https://verification.didit.me/v2/session', $payload, $headers);
            }
            
            if ($testRes['success']) {
                $url = $testRes['data']['url'] ?? ($testRes['data']['session_url'] ?? 'Session Created');
                $successMessage = '✅ Didit API Connection Successful! (HTTP ' . $testRes['http_code'] . ') Endpoint is reachable and valid. URL: ' . $url;
            } else {
                $detail = $testRes['error'] ?: 'HTTP ' . $testRes['http_code'];
                $resData = is_array($testRes['data']) ? $testRes['data'] : [];
                if (!empty($resData['message'])) {
                    $detail .= ' - ' . (is_array($resData['message']) ? json_encode($resData['message']) : $resData['message']);
                } elseif (!empty($resData['detail'])) {
                    $detail .= ' - ' . (is_array($resData['detail']) ? json_encode($resData['detail']) : $resData['detail']);
                }
                $errorMessage = '❌ Didit API Connection Test Failed (HTTP ' . $testRes['http_code'] . '): ' . $detail;
            }
        }
    }
}

$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'general');
if (!in_array($activeTab, ['general', 'didit', 'storage', 'risk'], true)) {
    $activeTab = 'general';
}

cv_admin_header('settings', 'Settings', 'Configure verification modes, Didit KYC integration, document storage, and risk parameters.');

?>

<style>
.cv-settings-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
    margin-bottom: 24px;
}
.cv-nav-tabs {
    display: flex;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0 16px;
    gap: 4px;
}
.cv-tab-item {
    padding: 14px 20px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    user-select: none;
    transition: all 0.15s ease;
}
.cv-tab-item:hover {
    color: #2563eb;
}
.cv-tab-item.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
    background: #ffffff;
}
.cv-tab-panel {
    display: none;
    padding: 24px;
}
.cv-tab-panel.active {
    display: block;
}
.cv-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f1f5f9;
}
.cv-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.cv-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cv-section-desc {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 16px;
}
.cv-field {
    margin-bottom: 16px;
}
.cv-field-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
.cv-field-hint {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
}
.cv-input-group {
    display: flex;
    position: relative;
}
.cv-btn-addon {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border-radius: 0 4px 4px 0;
    border-left: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.cv-btn-addon:hover {
    background: #e2e8f0;
}
.cv-input-with-addon {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
</style>

<?php if ($successMessage): ?>
    <div class="alert alert-success" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?>
    </div>
<?php endif; ?>

<?php if ($warningMessage): ?>
    <div class="alert alert-warning" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($warningMessage); ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-times-circle"></i> <?php echo htmlspecialchars($errorMessage); ?>
    </div>
<?php endif; ?>

<form method="post" id="cvSettingsForm">
<?php echo Csrf::field(); ?>
<input type="hidden" name="cv_save_settings" value="1">
<input type="hidden" name="active_tab" id="activeTabInput" value="<?php echo htmlspecialchars($activeTab); ?>">

<div class="cv-settings-card">
    <div class="cv-nav-tabs">
        <div class="cv-tab-item <?php echo $activeTab === 'general' ? 'active' : ''; ?>" onclick="cvSwitchTab('general')">
            <i class="fa fa-sliders"></i> General
        </div>
        <div class="cv-tab-item <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>" onclick="cvSwitchTab('notifications')">
            <i class="fa fa-envelope"></i> Notifications &amp; Routing
        </div>
        <div class="cv-tab-item <?php echo $activeTab === 'didit' ? 'active' : ''; ?>" onclick="cvSwitchTab('didit')">
            <i class="fa fa-id-badge"></i> Didit Automation
        </div>
        <div class="cv-tab-item <?php echo $activeTab === 'storage' ? 'active' : ''; ?>" onclick="cvSwitchTab('storage')">
            <i class="fa fa-lock"></i> Storage &amp; Files
        </div>
        <div class="cv-tab-item <?php echo $activeTab === 'risk' ? 'active' : ''; ?>" onclick="cvSwitchTab('risk')">
            <i class="fa fa-shield"></i> Risk Engine
        </div>
    </div>

    <!-- TAB 1: GENERAL -->
    <div class="cv-tab-panel <?php echo $activeTab === 'general' ? 'active' : ''; ?>" id="tab-general">
        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-power-off text-primary"></i> Module Activation &amp; Methods</div>
            <div class="cv-section-desc">Control which verification methods are available to your customers.</div>
            
            <div class="cv-field" style="margin-bottom: 14px;">
                <label style="font-size: 14px; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="enabled" value="yes" <?php echo cv_setting('enabled', 'yes') === 'yes' ? 'checked' : ''; ?>>
                    Enable Client Identity Verification Module
                </label>
                <div class="cv-field-hint">When unchecked, all verification prompts and restrictions are bypassed.</div>
            </div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="enable_didit" value="yes" <?php echo cv_setting('enable_didit', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <i class="fa fa-bolt text-primary"></i> Allow Didit AI Instant Verification
                    </label>
                    <div class="cv-field-hint">Allows clients to complete automated biometric &amp; AI document checks in 1-2 minutes.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="enable_manual" value="yes" <?php echo cv_setting('enable_manual', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <i class="fa fa-upload text-success"></i> Allow Manual Document Upload
                    </label>
                    <div class="cv-field-hint">Allows clients to submit passport, national ID, or driver's license for staff review.</div>
                </div>
            </div>
        </div>

        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-cogs text-primary"></i> Verification Workflow &amp; Retention</div>
            <div class="cv-section-desc">Select customer workflow preferences and log retention policies.</div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Default Workflow Mode</label>
                    <select name="verification_mode" class="form-control">
                        <option value="hybrid" <?php echo cv_setting('verification_mode', 'hybrid') === 'hybrid' ? 'selected' : ''; ?>>Hybrid (Client can choose Instant or Manual) [Recommended]</option>
                        <option value="didit" <?php echo cv_setting('verification_mode', 'hybrid') === 'didit' ? 'selected' : ''; ?>>Didit Automated Only (Instant AI Verification)</option>
                        <option value="manual" <?php echo cv_setting('verification_mode', 'hybrid') === 'manual' ? 'selected' : ''; ?>>Manual Only (Staff manually reviews all uploads)</option>
                    </select>
                    <div class="cv-field-hint">Hybrid gives the best customer experience with staff review as safety net.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Verification Validity (Days)</label>
                    <input type="number" name="verification_expiry_days" class="form-control" value="<?php echo htmlspecialchars(cv_setting('verification_expiry_days', '365')); ?>" min="1" max="3650">
                    <div class="cv-field-hint">Number of days an approved verification remains valid (default: 365 days).</div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Audit Log Retention (Days)</label>
                    <input type="number" name="audit_log_retention_days" class="form-control" value="<?php echo htmlspecialchars(cv_setting('audit_log_retention_days', '0')); ?>" min="0" max="3650">
                    <div class="cv-field-hint">Days to retain compliance audit logs. Enter <strong>0</strong> to keep logs forever (never auto-delete).</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Max Attempts per Hour</label>
                    <input type="number" name="rate_limit_attempts" class="form-control" value="<?php echo htmlspecialchars(cv_setting('rate_limit_attempts', '5')); ?>" min="1" max="50">
                    <div class="cv-field-hint">Rate limit for verification attempts per client/IP per hour.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: DIDIT AUTOMATION -->
    <div class="cv-tab-panel <?php echo $activeTab === 'didit' ? 'active' : ''; ?>" id="tab-didit">
        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-key text-primary"></i> Didit API Credentials</div>
            <div class="cv-section-desc">Enter your API keys from your Didit Developer Console.</div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Didit API Key</label>
                    <div class="cv-input-group">
                        <input type="password" id="didit_api_key" name="didit_api_key" class="form-control cv-input-with-addon" value="<?php echo htmlspecialchars(cv_setting('didit_api_key', '')); ?>" placeholder="Enter Didit API Key" autocomplete="new-password">
                        <button type="button" class="cv-btn-addon" onclick="cvTogglePassword('didit_api_key')">
                            <i class="fa fa-eye" id="didit_api_key_icon"></i> Show
                        </button>
                    </div>
                    <div class="cv-field-hint">Found in Didit Dashboard &gt; Developers &gt; API Keys.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Didit Workflow ID</label>
                    <input type="text" name="didit_workflow_id" class="form-control" value="<?php echo htmlspecialchars(cv_setting('didit_workflow_id', '')); ?>" placeholder="e.g. wf_xxxxxxxxxxxx">
                    <div class="cv-field-hint">The workflow identifier created in your Didit portal.</div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Didit Webhook Signing Secret</label>
                    <div class="cv-input-group">
                        <input type="password" id="didit_webhook_secret" name="didit_webhook_secret" class="form-control cv-input-with-addon" value="<?php echo htmlspecialchars(cv_setting('didit_webhook_secret', '')); ?>" placeholder="Enter Webhook Secret" autocomplete="new-password">
                        <button type="button" class="cv-btn-addon" onclick="cvTogglePassword('didit_webhook_secret')">
                            <i class="fa fa-eye" id="didit_webhook_secret_icon"></i> Show
                        </button>
                    </div>
                    <div class="cv-field-hint">Used to verify HMAC signatures of incoming Didit webhooks.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">On Didit Provider Error</label>
                    <select name="didit_on_error" class="form-control">
                        <option value="manual_review" <?php echo cv_setting('didit_on_error', 'manual_review') === 'manual_review' ? 'selected' : ''; ?>>Route to Manual Review (Recommended)</option>
                        <option value="reject" <?php echo cv_setting('didit_on_error', 'manual_review') === 'reject' ? 'selected' : ''; ?>>Reject Immediately</option>
                    </select>
                    <div class="cv-field-hint">Fallback behavior if the automated provider is unreachable.</div>
                </div>
            </div>

            <div class="cv-field" style="margin-bottom: 20px;">
                <label style="font-size: 14px; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="didit_auto_approve" value="1" <?php echo in_array(cv_setting('didit_auto_approve', '1'), ['1', 'yes', 'on'], true) ? 'checked' : ''; ?>>
                    Auto-Approve Client on Didit KYC Success
                </label>
                <div class="cv-field-hint">Automatically approves client verification when Didit passes and risk score is low.</div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong style="color: #1e293b; font-size: 13px;"><i class="fa fa-plug text-primary"></i> Test Didit Credentials</strong>
                    <div style="font-size: 12px; color: #64748b;">Verify API Key and Workflow ID against Didit Production endpoint.</div>
                </div>
                <button type="submit" name="cv_test_didit" value="1" class="btn btn-default btn-sm" style="font-weight: 600;">
                    <i class="fa fa-refresh"></i> Test Didit API Connection
                </button>
            </div>
        </div>

        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-link text-primary"></i> Inbound Webhook Destination URL</div>
            <div class="cv-section-desc">Paste this URL into your Didit Dashboard under <strong>API &amp; Webhooks &gt; Webhooks &gt; Add Destination</strong>:</div>

            <div class="cv-input-group" style="max-width: 680px;">
                <input type="text" id="cv_webhook_callback" class="form-control cv-input-with-addon" value="<?php echo htmlspecialchars(cv_webhook_url()); ?>" readonly style="background: #f8fafc; font-family: monospace;">
                <button type="button" class="cv-btn-addon" onclick="navigator.clipboard.writeText(document.getElementById('cv_webhook_callback').value); alert('Webhook Destination URL copied to clipboard!');">
                    <i class="fa fa-copy"></i> Copy URL
                </button>
            </div>
            <div class="cv-field-hint" style="margin-top: 6px;">
                Browser Redirect Callback URL (sent automatically per session): <code><?php echo htmlspecialchars(cv_callback_url()); ?></code>
            </div>
        </div>
    </div>

    <!-- TAB 3: STORAGE & FILES -->
    <div class="cv-tab-panel <?php echo $activeTab === 'storage' ? 'active' : ''; ?>" id="tab-storage">
        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-folder-open text-primary"></i> Document Storage Path</div>
            <div class="cv-section-desc">Where manual verification files are saved on the server.</div>

            <div class="cv-field">
                <label class="cv-field-label">Custom Storage Path (Outside public_html)</label>
                <input type="text" name="storage_path" class="form-control" value="<?php echo htmlspecialchars(cv_setting('storage_path', '')); ?>" placeholder="e.g., /home/username/kyc_secure_files">
                <div class="cv-field-hint">Leave blank to use the module default secure storage directory.</div>
            </div>
        </div>

        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-lock text-primary"></i> Document At-Rest Encryption</div>
            <div class="cv-section-desc">Encrypt uploaded documents with AES-256 before saving to disk.</div>

            <div class="cv-field">
                <label style="font-size: 14px; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="storage_encryption" value="1" <?php echo in_array(cv_setting('storage_encryption', '0'), ['1', 'yes', 'on'], true) ? 'checked' : ''; ?>>
                    Enable AES-256 Document Encryption at Rest
                </label>
                <div class="cv-field-hint">Files are decrypted automatically when authorized admins view or download them.</div>
            </div>

            <div class="row">
                <div class="col-md-7 cv-field">
                    <label class="cv-field-label">Custom Encryption Key (Optional)</label>
                    <div class="cv-input-group">
                        <input type="password" id="encryption_key" name="encryption_key" class="form-control cv-input-with-addon" value="<?php echo htmlspecialchars(cv_setting('encryption_key', '')); ?>" placeholder="Leave blank to use default derived key" autocomplete="new-password">
                        <button type="button" class="cv-btn-addon" onclick="cvTogglePassword('encryption_key')">
                            <i class="fa fa-eye" id="encryption_key_icon"></i>
                        </button>
                        <button type="button" class="cv-btn-addon" style="border-left: 1px solid #cbd5e1;" onclick="cvGenerateKey()">
                            <i class="fa fa-magic"></i> Generate
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-upload text-primary"></i> Upload Restrictions</div>
            <div class="cv-section-desc">File constraints for customer document uploads.</div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Max File Size (MB)</label>
                    <input type="number" name="max_file_size" class="form-control" value="<?php echo htmlspecialchars(cv_setting('max_file_size', '10')); ?>" min="1" max="100">
                    <div class="cv-field-hint">Maximum upload size per document (default: 10 MB).</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Allowed Extensions</label>
                    <input type="text" name="allowed_extensions" class="form-control" value="<?php echo htmlspecialchars(cv_setting('allowed_extensions', 'pdf,jpg,jpeg,png,webp')); ?>">
                    <div class="cv-field-hint">Comma-separated list (e.g. pdf,jpg,jpeg,png,webp).</div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 4: RISK ENGINE -->
    <div class="cv-tab-panel <?php echo $activeTab === 'risk' ? 'active' : ''; ?>" id="tab-risk">
        <div class="cv-section">
            <div class="cv-section-title"><i class="fa fa-tachometer text-primary"></i> Automated Risk Scoring Thresholds</div>
            <div class="cv-section-desc">Define automated scoring cutoffs for AI decision-making (0 to 100 scale).</div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Auto-Approve Threshold (0 - 100)</label>
                    <input type="number" name="risk_threshold_approve" class="form-control" value="<?php echo htmlspecialchars(cv_setting('risk_threshold_approve', '30')); ?>" min="0" max="100">
                    <div class="cv-field-hint">Risk score <strong>equal or below</strong> this value will be automatically approved (default: 30).</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label class="cv-field-label">Manual Review Threshold (0 - 100)</label>
                    <input type="number" name="risk_threshold_review" class="form-control" value="<?php echo htmlspecialchars(cv_setting('risk_threshold_review', '70')); ?>" min="0" max="100">
                    <div class="cv-field-hint">Risk score above this cutoff will be automatically rejected. Scores in between trigger staff review.</div>
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

    <!-- TAB: NOTIFICATIONS & ROUTING -->
    <div class="cv-tab-panel <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>" id="tab-notifications">
        <!-- Client / User Email Notifications -->
        <div class="cv-section">
            <div class="cv-section-title">
                <i class="fa fa-user text-primary"></i> Client (User) Email Notifications
            </div>
            <div class="cv-section-desc">
                Configure which email notifications are automatically sent to clients during their verification lifecycle using native WHMCS email templates.
            </div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_client_started" value="yes" <?php echo cv_setting('mail_client_started', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-play text-primary"></i> Verification Started</span>
                    </label>
                    <div class="cv-field-hint">Send email when a client initiates a new verification session.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_client_under_review" value="yes" <?php echo cv_setting('mail_client_under_review', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-clock-o text-warning"></i> Documents Under Review</span>
                    </label>
                    <div class="cv-field-hint">Send email confirming submitted documents are under compliance review.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_client_approved" value="yes" <?php echo cv_setting('mail_client_approved', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-check-circle text-success"></i> Verification Approved</span>
                    </label>
                    <div class="cv-field-hint">Send congratulatory email when identity documents are fully approved.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_client_rejected" value="yes" <?php echo cv_setting('mail_client_rejected', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-times-circle text-danger"></i> Verification Rejected</span>
                    </label>
                    <div class="cv-field-hint">Send email notifying the client of rejection with compliance reason.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_client_info_requested" value="yes" <?php echo cv_setting('mail_client_info_requested', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-info-circle text-info"></i> Additional Information Requested</span>
                    </label>
                    <div class="cv-field-hint">Send email when admin requests clearer photos or specific documents.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_client_expiring" value="yes" <?php echo cv_setting('mail_client_expiring', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-calendar text-warning"></i> Verification Expiring / Expired</span>
                    </label>
                    <div class="cv-field-hint">Send annual expiry reminders and expired notices to clients via cron.</div>
                </div>
            </div>
        </div>

        <!-- Admin Alerts & Routing -->
        <div class="cv-section">
            <div class="cv-section-title">
                <i class="fa fa-shield text-danger"></i> Admin &amp; Staff Notification Alerts
            </div>
            <div class="cv-section-desc">
                Control which critical KYC events trigger notifications to your compliance team and configure destination email addresses.
            </div>

            <div class="row">
                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_admin_new_submission" value="yes" <?php echo cv_setting('mail_admin_new_submission', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-file-text-o text-primary"></i> New Manual Document Submission</span>
                    </label>
                    <div class="cv-field-hint">Alert staff when a customer uploads identity documents requiring review.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_admin_high_risk" value="yes" <?php echo cv_setting('mail_admin_high_risk', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-exclamation-triangle text-danger"></i> High Risk Verification Detected</span>
                    </label>
                    <div class="cv-field-hint">Urgent alert when Risk Engine or AI flags high risk or suspicious traits.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_admin_info_response" value="yes" <?php echo cv_setting('mail_admin_info_response', 'yes') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-reply text-info"></i> Client Uploaded Requested Info</span>
                    </label>
                    <div class="cv-field-hint">Alert staff when a client submits requested documents following an info request.</div>
                </div>

                <div class="col-md-6 cv-field">
                    <label style="font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="mail_admin_didit_completed" value="yes" <?php echo cv_setting('mail_admin_didit_completed', 'no') === 'yes' ? 'checked' : ''; ?>>
                        <span><i class="fa fa-bolt text-success"></i> Didit AI Verification Finished</span>
                    </label>
                    <div class="cv-field-hint">Send alert every time an automated Didit AI biometric session completes.</div>
                </div>
            </div>

            <!-- Admin Recipient Emails -->
            <div class="cv-field" style="margin-top: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px;">
                <label class="cv-field-label" style="font-size: 14px; font-weight: 700; color: #1e293b;">
                    <i class="fa fa-envelope-o text-primary"></i> Admin Alert Recipient Email Address(es)
                </label>
                <input type="text" name="admin_notification_emails" class="form-control" value="<?php echo htmlspecialchars(cv_setting('admin_notification_emails', '')); ?>" placeholder="e.g. compliance@yourdomain.com, security@yourdomain.com">
                <div class="cv-field-hint" style="margin-top: 6px; font-size: 12px; color: #64748b;">
                    <i class="fa fa-info-circle"></i> Enter specific email addresses separated by commas (<code>,</code>) to route KYC admin alerts directly. If left blank, notifications will be sent to default WHMCS System Administrators.
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

<script>
function cvSwitchTab(tabName) {
    document.querySelectorAll('.cv-tab-item').forEach(function(el) {
        el.classList.remove('active');
    });
    document.querySelectorAll('.cv-tab-panel').forEach(function(el) {
        el.classList.remove('active');
    });
    
    var clickedTab = Array.from(document.querySelectorAll('.cv-tab-item')).find(function(el) {
        return el.textContent.toLowerCase().includes(tabName);
    });
    if (clickedTab) clickedTab.classList.add('active');
    
    var panel = document.getElementById('tab-' + tabName);
    if (panel) panel.classList.add('active');

    document.getElementById('activeTabInput').value = tabName;
}

function cvTogglePassword(id) {
    var input = document.getElementById(id);
    var icon = document.getElementById(id + '_icon');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        input.type = 'password';
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}

function cvGenerateKey() {
    var k = Array.from(crypto.getRandomValues(new Uint8Array(24))).map(function(b) {
        return b.toString(16).padStart(2, '0');
    }).join('');
    var input = document.getElementById('encryption_key');
    if (input) {
        input.value = k;
        input.type = 'text';
    }
}
</script>


