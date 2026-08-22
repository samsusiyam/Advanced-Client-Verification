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
            $errorMessage = $res['message'] ?? 'Activation failed. Please check your license key and domain binding.';
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
    }
}

$details = $licenseManager->getDetails(true);
$isLicensed = $details['is_licensed'];
$status = strtolower($details['status']);

// Determine theme styling based on license state
$bannerBg = 'linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%)';
$bannerBorder = '#fed7aa';
$bannerIconBg = '#ea580c';
$bannerIcon = 'fa-lock';
$bannerTitle = 'License Activation Required';
$bannerTitleColor = '#7c2d12';
$bannerDescColor = '#9a3412';
$badgeBg = '#f97316';
$badgeText = strtoupper($status);
$bannerDesc = 'Enter your valid license key below to unlock automated KYC verification and client management.';

if ($status === 'active') {
    $bannerBg = 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)';
    $bannerBorder = '#bbf7d0';
    $bannerIconBg = '#16a34a';
    $bannerIcon = 'fa-shield';
    $bannerTitle = 'License Active & Genuine';
    $bannerTitleColor = '#14532d';
    $bannerDescColor = '#166534';
    $badgeBg = '#22c55e';
    $badgeText = 'ACTIVE';
    $bannerDesc = 'Your module is licensed, verified, and running with active protection.';
} elseif ($status === 'suspended') {
    $bannerBg = 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)';
    $bannerBorder = '#fecaca';
    $bannerIconBg = '#dc2626';
    $bannerIcon = 'fa-ban';
    $bannerTitle = 'License Suspended';
    $bannerTitleColor = '#991b1b';
    $bannerDescColor = '#b91c1c';
    $badgeBg = '#dc2626';
    $badgeText = 'SUSPENDED';
    $bannerDesc = 'Your license has been suspended by HostNibo. Automated KYC verification and admin features are temporarily locked.';
} elseif ($status === 'terminated') {
    $bannerBg = 'linear-gradient(135deg, #450a0a 0%, #7f1d1d 100%)';
    $bannerBorder = '#991b1b';
    $bannerIconBg = '#000000';
    $bannerIcon = 'fa-times-circle';
    $bannerTitle = 'License Terminated';
    $bannerTitleColor = '#ffffff';
    $bannerDescColor = '#fecaca';
    $badgeBg = '#000000';
    $badgeText = 'TERMINATED';
    $bannerDesc = 'This license has been permanently terminated. Please obtain a new license to continue using the module.';
} elseif ($status === 'domain_mismatch') {
    $bannerBg = 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)';
    $bannerBorder = '#fecaca';
    $bannerIconBg = '#e11d48';
    $bannerIcon = 'fa-globe';
    $bannerTitle = 'Domain Mismatch';
    $bannerTitleColor = '#9f1239';
    $bannerDescColor = '#be123c';
    $badgeBg = '#e11d48';
    $badgeText = 'DOMAIN MISMATCH';
    $bannerDesc = 'This license is registered to a different domain. Please re-verify or activate a license valid for ' . htmlspecialchars($details['domain']) . '.';
} elseif ($status === 'expired') {
    $bannerBg = 'linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%)';
    $bannerBorder = '#fecdd3';
    $bannerIconBg = '#e11d48';
    $bannerIcon = 'fa-calendar-times-o';
    $bannerTitle = 'License Expired';
    $bannerTitleColor = '#881337';
    $bannerDescColor = '#9f1239';
    $badgeBg = '#e11d48';
    $badgeText = 'EXPIRED';
    $bannerDesc = 'Your product license subscription has expired. Please renew your license key on HostNibo.';
}

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
    <div style="padding: 24px 28px; background: <?php echo $bannerBg; ?>; border-bottom: 1px solid <?php echo $bannerBorder; ?>; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 54px; height: 54px; border-radius: 50%; background: <?php echo $bannerIconBg; ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 26px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <i class="fa <?php echo $bannerIcon; ?>"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h3 style="margin: 0; font-size: 20px; font-weight: 700; color: <?php echo $bannerTitleColor; ?>;">
                        <?php echo $bannerTitle; ?>
                    </h3>
                    <span style="background: <?php echo $badgeBg; ?>; color: #ffffff; font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 12px; text-transform: uppercase;">
                        <?php echo htmlspecialchars($badgeText); ?>
                    </span>
                </div>
                <p style="margin: 4px 0 0 0; color: <?php echo $bannerDescColor; ?>; font-size: 13px;">
                    <?php echo htmlspecialchars($bannerDesc); ?>
                </p>
            </div>
        </div>

        <div style="display: flex; gap: 8px;">
            <form method="POST" style="margin: 0;">
                <?php echo Csrf::field(); ?>
                <button type="submit" name="cv_verify_license" value="1" class="btn btn-default btn-sm" style="background: #ffffff; font-weight: 600; border-color: #cbd5e1;">
                    <i class="fa fa-refresh"></i> Re-verify
                </button>
            </form>
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
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Expiry Date</div>
                    <div style="font-size: 13px; font-weight: 600; color: <?php echo $details['expiry_date'] === 'Lifetime / Ongoing' ? '#16a34a' : '#0f172a'; ?>;">
                        <i class="fa fa-calendar"></i> <?php echo htmlspecialchars($details['expiry_date']); ?>
                    </div>
                </div>
            </div>

            <div class="col-md-9 col-sm-6" style="margin-bottom: 18px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Server Verification Message</div>
                    <div style="font-size: 13px; font-weight: 600; color: <?php echo $isLicensed ? '#16a34a' : '#dc2626'; ?>;">
                        <i class="fa <?php echo $isLicensed ? 'fa-check' : 'fa-info-circle'; ?>"></i> <?php echo htmlspecialchars($details['message'] ?: ($isLicensed ? 'License active and valid.' : 'Unlicensed install')); ?>
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
</script>
