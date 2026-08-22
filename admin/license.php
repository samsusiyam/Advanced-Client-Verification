<?php

use ClientVerification\License\LicenseManager;
use ClientVerification\Security\Csrf;
use ClientVerification\Security\Sanitizer;

$adminId = (int) ($_SESSION['adminid'] ?? 0);
$successMessage = '';
$warningMessage = '';
$errorMessage = '';

$licenseManager = LicenseManager::getInstance();

// -----------------------------------------------------------------------------
// POST Actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['cv_token'] ?? null)) {
        $errorMessage = 'Security token expired or invalid. Please try again.';
    } elseif (isset($_POST['cv_activate_license'])) {
        $newKey = trim($_POST['license_key'] ?? '');
        $res = $licenseManager->activate($newKey);
        if (!empty($res['status'])) {
            $successMessage = $res['message'] ?? 'License activated successfully!';
        } else {
            $errorMessage = $res['message'] ?? 'Activation failed. Please check your license key and server connection.';
        }
    } elseif (isset($_POST['cv_verify_license'])) {
        $res = $licenseManager->verify(true);
        if (!empty($res['status'])) {
            $successMessage = '✅ License verified successfully! Status: Active (Expires: ' . ($res['data']['expiry'] ?? 'Lifetime') . ')';
        } else {
            $errorMessage = '❌ License check failed: ' . ($res['message'] ?? 'Unknown verification error');
        }
    } elseif (isset($_POST['cv_deactivate_license'])) {
        $res = $licenseManager->deactivate();
        if (!empty($res['status'])) {
            $successMessage = $res['message'] ?? 'License deactivated.';
        } else {
            $errorMessage = $res['message'] ?? 'Deactivation failed.';
        }
    } elseif (isset($_POST['cv_check_update'])) {
        $res = $licenseManager->checkUpdate('1.0.0');
        if (!empty($res['status'])) {
            $latest = $res['data']['latest_version'] ?? '1.0.0';
            if (version_compare($latest, '1.0.0', '>')) {
                $warningMessage = '🎉 A new version (' . htmlspecialchars($latest) . ') is available! Download URL: ' . htmlspecialchars($res['data']['download_url'] ?? 'HostNibo Client Portal');
            } else {
                $successMessage = '✅ You are running the latest version of Advanced Client Verification (v1.0.0).';
            }
        } else {
            $errorMessage = 'Update check failed: ' . ($res['message'] ?? 'Server unreachable');
        }
    } elseif (isset($_POST['cv_save_license_settings'])) {
        $srv = trim($_POST['license_server_url'] ?? '');
        $pk  = trim($_POST['license_product_key'] ?? '');
        $ak  = trim($_POST['license_api_key'] ?? '');
        $sk  = trim($_POST['license_api_secret'] ?? '');

        if (!empty($srv)) {
            cv_setting_set('license_server_url', rtrim($srv, '/'));
        }
        if (!empty($pk)) {
            cv_setting_set('license_product_key', $pk);
        }
        if (!empty($ak)) {
            cv_setting_set('license_api_key', $ak);
        }
        if (!empty($sk)) {
            cv_setting_set('license_api_secret', $sk);
        }

        $successMessage = 'License server connection settings updated successfully.';
    }
}

$details = $licenseManager->getDetails();
$isLicensed = $details['is_licensed'];

cv_admin_header('license', 'License & Activation', 'Manage your HostNibo product license, activation, and automated updates.');
?>

<?php if (!empty($successMessage)): ?>
    <div class="alert alert-success" style="border-radius: 8px; font-weight: 500; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 8px;">
        <i class="fa fa-check-circle" style="font-size: 18px;"></i>
        <div><?php echo $successMessage; ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($warningMessage)): ?>
    <div class="alert alert-warning" style="border-radius: 8px; font-weight: 500; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 8px;">
        <i class="fa fa-exclamation-triangle" style="font-size: 18px;"></i>
        <div><?php echo $warningMessage; ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger" style="border-radius: 8px; font-weight: 500; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 8px;">
        <i class="fa fa-times-circle" style="font-size: 18px;"></i>
        <div><?php echo htmlspecialchars($errorMessage); ?></div>
    </div>
<?php endif; ?>

<!-- Main Status Banner -->
<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.03); margin-bottom: 24px; overflow: hidden;">
    <div style="padding: 24px 28px; background: <?php echo $isLicensed ? 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)' : 'linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%)'; ?>; border-bottom: 1px solid <?php echo $isLicensed ? '#bbf7d0' : '#fed7aa'; ?>; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 54px; height: 54px; border-radius: 50%; background: <?php echo $isLicensed ? '#16a34a' : '#ea580c'; ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 26px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <i class="fa <?php echo $isLicensed ? 'fa-shield' : 'fa-lock'; ?>"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h3 style="margin: 0; font-size: 20px; font-weight: 700; color: <?php echo $isLicensed ? '#14532d' : '#7c2d12'; ?>;">
                        <?php echo $isLicensed ? 'License Active & Genuine' : 'License Activation Required'; ?>
                    </h3>
                    <span style="background: <?php echo $isLicensed ? '#22c55e' : '#f97316'; ?>; color: #ffffff; font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 12px; text-transform: uppercase;">
                        <?php echo htmlspecialchars($details['status']); ?>
                    </span>
                </div>
                <p style="margin: 4px 0 0 0; color: <?php echo $isLicensed ? '#166534' : '#9a3412'; ?>; font-size: 13px;">
                    <?php if ($isLicensed): ?>
                        Your module is licensed, verified, and protected by HostNibo External License Management System.
                    <?php else: ?>
                        Enter your valid license key below to unlock automated KYC verification and client management.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div style="display: flex; gap: 8px;">
            <?php if ($isLicensed): ?>
                <form method="POST" style="margin: 0;">
                    <?php echo Csrf::field(); ?>
                    <button type="submit" name="cv_verify_license" value="1" class="btn btn-default btn-sm" style="background: #ffffff; font-weight: 600; border-color: #cbd5e1;">
                        <i class="fa fa-refresh"></i> Re-verify
                    </button>
                </form>
                <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to deactivate this license? This will free the activation slot on the license server.');">
                    <?php echo Csrf::field(); ?>
                    <button type="submit" name="cv_deactivate_license" value="1" class="btn btn-danger btn-sm" style="font-weight: 600;">
                        <i class="fa fa-power-off"></i> Deactivate
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- License Information Grid -->
    <div style="padding: 24px 28px;">
        <div class="row">
            <div class="col-md-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">License Key</div>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span id="cv-lic-key-display" style="font-family: monospace; font-size: 15px; font-weight: 700; color: #1e293b;">
                            <?php echo htmlspecialchars($details['masked_key'] ?: 'None configured'); ?>
                        </span>
                        <?php if (!empty($details['license_key'])): ?>
                            <button type="button" class="btn btn-xs btn-default" onclick="toggleLicKey()" title="Show/Hide Key">
                                <i class="fa fa-eye" id="cv-lic-eye"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Registered Domain</div>
                    <div style="font-size: 14px; font-weight: 600; color: #0f172a; word-break: break-all;">
                        <i class="fa fa-globe" style="color: #3b82f6;"></i> <?php echo htmlspecialchars($details['domain']); ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Server IP</div>
                    <div style="font-size: 14px; font-weight: 600; color: #0f172a;">
                        <i class="fa fa-server" style="color: #6366f1;"></i> <?php echo htmlspecialchars($details['ip']); ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Product Key</div>
                    <div style="font-size: 13px; font-weight: 600; color: #0f172a;">
                        <?php echo htmlspecialchars($details['product_key']); ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Expiry Date</div>
                    <div style="font-size: 13px; font-weight: 600; color: <?php echo $details['expiry_date'] === 'Lifetime / Ongoing' ? '#16a34a' : '#0f172a'; ?>;">
                        <i class="fa fa-calendar"></i> <?php echo htmlspecialchars($details['expiry_date']); ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Last Verified</div>
                    <div style="font-size: 13px; font-weight: 600; color: #0f172a;">
                        <i class="fa fa-clock-o"></i> <?php echo htmlspecialchars($details['last_check']); ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">License Server</div>
                    <div style="font-size: 13px; font-weight: 600; color: #2563eb; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <a href="<?php echo htmlspecialchars($details['server_url']); ?>" target="_blank" style="color: #2563eb; text-decoration: none;">
                            <?php echo htmlspecialchars($details['server_url']); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Action Cards Grid -->
<div class="row">
    <!-- License Activation / Key Update -->
    <div class="col-md-6">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 24px;">
            <h4 style="margin: 0 0 14px 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <i class="fa fa-key" style="color: #2563eb;"></i> <?php echo $isLicensed ? 'Change / Update License Key' : 'Activate Product License'; ?>
            </h4>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                Enter the license key received upon purchasing the module from HostNibo or an authorized reseller.
            </p>

            <form method="POST">
                <?php echo Csrf::field(); ?>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 13px; color: #334155;">License Key</label>
                    <input type="text" name="license_key" class="form-control" placeholder="e.g. ACV-XXXX-XXXX-XXXX-XXXX" value="<?php echo htmlspecialchars($details['license_key']); ?>" required style="font-family: monospace; font-size: 14px; font-weight: 600;">
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="cv_activate_license" value="1" class="btn btn-primary" style="font-weight: 600; padding: 8px 20px;">
                        <i class="fa fa-check-circle"></i> <?php echo $isLicensed ? 'Update & Activate Key' : 'Activate License Now'; ?>
                    </button>
                    <a href="https://hostnibo.com" target="_blank" class="btn btn-default" style="font-weight: 600;">
                        <i class="fa fa-shopping-cart"></i> Buy License
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Software Updates & Release Check -->
    <div class="col-md-6">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 24px;">
            <h4 style="margin: 0 0 14px 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <i class="fa fa-cloud-download" style="color: #059669;"></i> Module Version & Updates
            </h4>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                Check the ELMS update stream for new security patches, provider integrations, and performance enhancements.
            </p>

            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
                <div>
                    <div style="font-size: 12px; color: #64748b; font-weight: 600;">CURRENT INSTALLED VERSION</div>
                    <div style="font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 2px;">v1.0.0 <span style="font-size: 11px; font-weight: 600; color: #16a34a; background: #dcfce7; padding: 2px 8px; border-radius: 10px;">Stable</span></div>
                </div>
                <div>
                    <form method="POST" style="margin: 0;">
                        <?php echo Csrf::field(); ?>
                        <button type="submit" name="cv_check_update" value="1" class="btn btn-default btn-sm" style="font-weight: 600;">
                            <i class="fa fa-refresh"></i> Check for Updates
                        </button>
                    </form>
                </div>
            </div>

            <div style="font-size: 12px; color: #94a3b8; line-height: 1.5;">
                <i class="fa fa-info-circle"></i> Automatic update reminders are also synchronized daily via WHMCS Daily Cron.
            </div>
        </div>
    </div>
</div>

<!-- Advanced Connection Settings (Collapsible) -->
<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 24px; overflow: hidden;">
    <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="toggleAdvancedSettings()">
        <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 8px;">
            <i class="fa fa-cog"></i> Advanced License Server Settings
        </h4>
        <span id="cv-adv-toggle-icon" style="color: #64748b;"><i class="fa fa-chevron-down"></i></span>
    </div>

    <div id="cv-advanced-settings-body" style="padding: 20px; display: none;">
        <form method="POST">
            <?php echo Csrf::field(); ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 13px;">License Server Base URL</label>
                        <input type="text" name="license_server_url" class="form-control" value="<?php echo htmlspecialchars($details['server_url']); ?>" placeholder="https://lic.hostnibo.com">
                        <small class="text-muted">The root URL of your HostNibo ELMS License Server (without trailing slash).</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 13px;">Product Key Identifier</label>
                        <input type="text" name="license_product_key" class="form-control" value="<?php echo htmlspecialchars($details['product_key']); ?>" placeholder="ADVANCED-CLIENT-VERIFICATION">
                        <small class="text-muted">Matches the Product Key on the ELMS server.</small>
                    </div>
                </div>
            </div>
            <div class="row" style="margin-top: 10px;">
                <div class="col-md-6">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 13px;">API Public Key (Optional override)</label>
                        <input type="text" name="license_api_key" class="form-control" value="<?php echo htmlspecialchars($licenseManager->resolveApiKey()); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 13px;">API Secret Key (Optional override)</label>
                        <input type="password" name="license_api_secret" class="form-control" value="<?php echo htmlspecialchars($licenseManager->resolveApiSecret()); ?>">
                    </div>
                </div>
            </div>
            <div style="margin-top: 14px;">
                <button type="submit" name="cv_save_license_settings" value="1" class="btn btn-default btn-sm" style="font-weight: 600;">
                    <i class="fa fa-save"></i> Save Server Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var isFullKeyShown = false;
var fullKey = <?php echo json_encode($details['license_key']); ?>;
var maskedKey = <?php echo json_encode($details['masked_key'] ?: 'None configured'); ?>;

function toggleLicKey() {
    var elem = document.getElementById('cv-lic-key-display');
    var icon = document.getElementById('cv-lic-eye');
    if (!elem) return;
    if (isFullKeyShown) {
        elem.textContent = maskedKey;
        if (icon) icon.className = 'fa fa-eye';
        isFullKeyShown = false;
    } else {
        elem.textContent = fullKey;
        if (icon) icon.className = 'fa fa-eye-slash';
        isFullKeyShown = true;
    }
}

function toggleAdvancedSettings() {
    var body = document.getElementById('cv-advanced-settings-body');
    var icon = document.getElementById('cv-adv-toggle-icon');
    if (!body) return;
    if (body.style.display === 'none') {
        body.style.display = 'block';
        if (icon) icon.innerHTML = '<i class="fa fa-chevron-up"></i>';
    } else {
        body.style.display = 'none';
        if (icon) icon.innerHTML = '<i class="fa fa-chevron-down"></i>';
    }
}
</script>
