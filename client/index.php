<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$verification = \ClientVerification\Services\VerificationService::getActiveForClient($clientId);
$config = cv_get_config();
$mode = $config['verification_mode'] ?? 'hybrid';

$status = $verification ? $verification->status : 'unverified';

?>

<div style="max-width: 680px; margin: 30px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); padding: 32px 28px; text-align: center; color: #ffffff;">
        <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
            <i class="fa fa-shield fa-2x" style="color: #ffffff;"></i>
        </div>
        <h2 style="margin: 0 0 6px 0; font-size: 24px; font-weight: 700; color: #ffffff;">Identity Verification</h2>
        <p style="margin: 0; opacity: 0.9; font-size: 14px;">Secure and seamless identity verification process</p>
    </div>

    <div style="padding: 32px 28px; text-align: center;">
        <?php if ($status === 'approved'): ?>
            <div style="width: 72px; height: 72px; background: #dcfce7; color: #16a34a; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; font-size: 32px;">
                <i class="fa fa-check"></i>
            </div>
            <h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #166534;">Identity Verified</h3>
            <p style="color: #4b5563; font-size: 14px; margin-bottom: 24px;">Your identity has been verified successfully. You have full access to all services.</p>
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; display: inline-block; font-size: 13px; color: #15803d; font-weight: 600;">
                <i class="fa fa-lock"></i> Verification Reference: <?php echo htmlspecialchars($verification->client_ref ?: 'CV-' . $verification->id); ?>
            </div>

        <?php elseif ($status === 'under_review'): ?>
            <div style="width: 72px; height: 72px; background: #fef3c7; color: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; font-size: 32px;">
                <i class="fa fa-clock-o"></i>
            </div>
            <h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #92400e;">Verification Under Review</h3>
            <p style="color: #4b5563; font-size: 14px; margin-bottom: 24px;">Your submitted documents are currently being reviewed by our compliance team. We will notify you once completed.</p>
            <a href="index.php?m=clientverification&action=verification&id=<?php echo (int) $verification->id; ?>" class="btn btn-default" style="font-weight: 600;">
                <i class="fa fa-eye"></i> View Submission Status
            </a>

        <?php elseif ($status === 'rejected'): 
            $rejectionReason = $verification->rejection_reason ?? '';
            if (empty($rejectionReason)) {
                try {
                    $lastRejectLog = Capsule::table('mod_cv_audit_logs')
                        ->where('verification_id', $verification->id)
                        ->whereIn('action', ['status_rejected', 'rejected', 'admin_rejected'])
                        ->orderByDesc('id')
                        ->first();
                    if ($lastRejectLog && !empty($lastRejectLog->details) && $lastRejectLog->details !== 'admin_rejected') {
                        $rejectionReason = $lastRejectLog->details;
                    }
                } catch (\Throwable $e) {}
            }

            $enableDidit = cv_setting('enable_didit', 'yes') === 'yes';
            $enableManual = cv_setting('enable_manual', 'yes') === 'yes';
            $vMode = cv_setting('verification_mode', 'hybrid');
            $hasDidit = !empty($config['didit_api_key'] ?? ($config['api_key'] ?? '')) && !empty($config['didit_workflow_id'] ?? ($config['workflow_id'] ?? ''));
            $canDidit = $enableDidit && $hasDidit && in_array($vMode, ['hybrid', 'didit']);
            $canManual = $enableManual && in_array($vMode, ['hybrid', 'manual']);
        ?>
            <div style="width: 68px; height: 68px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 30px;">
                <i class="fa fa-times"></i>
            </div>
            <h3 style="margin: 0 0 6px 0; font-size: 20px; font-weight: 700; color: #991b1b;">Verification Unsuccessful</h3>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 18px;">Your previous identity verification could not be approved.</p>

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px; text-align: left; max-width: 520px; margin-left: auto; margin-right: auto;">
                <div style="font-size: 12px; font-weight: 700; color: #991b1b; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa fa-exclamation-circle"></i> Reason for Rejection:
                </div>
                <div style="font-size: 13px; color: #7f1d1d; line-height: 1.4;">
                    <?php echo htmlspecialchars($rejectionReason ?: 'The submitted documents were unclear, invalid, or did not meet compliance standards. Please submit new, clear documents.'); ?>
                </div>
            </div>

            <div style="border-top: 1px solid #e2e8f0; padding-top: 22px; margin-top: 10px;">
                <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e293b;">Start New Verification</h4>
                <p style="color: #64748b; font-size: 13px; margin-bottom: 20px;">Please choose a method below to restart your verification:</p>

                <?php if ($canDidit && $canManual): ?>
                    <div class="row" style="text-align: left; margin-bottom: 10px;">
                        <!-- Option 1: Instant Didit AI -->
                        <div class="col-sm-6" style="margin-bottom: 16px;">
                            <div style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 18px 16px; height: 100%; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease;">
                                <div>
                                    <div style="width: 40px; height: 40px; background: #eff6ff; color: #2563eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 10px;">
                                        <i class="fa fa-bolt"></i>
                                    </div>
                                    <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 700; color: #1e293b;">Instant Verification</h4>
                                    <p style="font-size: 12px; color: #64748b; margin-bottom: 14px; line-height: 1.4;">AI biometric check and instant document scanning.</p>
                                </div>
                                <a href="index.php?m=clientverification&action=start&method=didit" class="btn btn-primary btn-block" style="font-weight: 600; padding: 8px 14px; border-radius: 6px;">
                                    <i class="fa fa-flash"></i> Verify with Didit AI &raquo;
                                </a>
                            </div>
                        </div>

                        <!-- Option 2: Manual Upload -->
                        <div class="col-sm-6" style="margin-bottom: 16px;">
                            <div style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 18px 16px; height: 100%; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease;">
                                <div>
                                    <div style="width: 40px; height: 40px; background: #f0fdf4; color: #16a34a; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 10px;">
                                        <i class="fa fa-cloud-upload"></i>
                                    </div>
                                    <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 700; color: #1e293b;">Manual Upload</h4>
                                    <p style="font-size: 12px; color: #64748b; margin-bottom: 14px; line-height: 1.4;">Upload photos of your ID or Passport for review.</p>
                                </div>
                                <a href="index.php?m=clientverification&action=start&method=manual" class="btn btn-success btn-block" style="font-weight: 600; padding: 8px 14px; border-radius: 6px; background: #16a34a; border-color: #16a34a;">
                                    <i class="fa fa-upload"></i> Upload Documents &raquo;
                                </a>
                            </div>
                        </div>
                    </div>
                <?php elseif ($canDidit): ?>
                    <a href="index.php?m=clientverification&action=start&method=didit" class="btn btn-primary btn-lg" style="font-weight: 600; padding: 12px 32px; border-radius: 6px;">
                        <i class="fa fa-flash"></i> Start Instant Verification &raquo;
                    </a>
                <?php else: ?>
                    <a href="index.php?m=clientverification&action=start&method=manual" class="btn btn-success btn-lg" style="font-weight: 600; padding: 12px 32px; border-radius: 6px; background: #16a34a; border-color: #16a34a;">
                        <i class="fa fa-upload"></i> Upload Documents &raquo;
                    </a>
                <?php endif; ?>
            </div>

        <?php else: 
            $enableDidit = cv_setting('enable_didit', 'yes') === 'yes';
            $enableManual = cv_setting('enable_manual', 'yes') === 'yes';
            $mode = cv_setting('verification_mode', 'hybrid');

            $hasDidit = !empty($config['didit_api_key'] ?? ($config['api_key'] ?? '')) && !empty($config['didit_workflow_id'] ?? ($config['workflow_id'] ?? ''));

            $canDidit = $enableDidit && $hasDidit && in_array($mode, ['hybrid', 'didit']);
            $canManual = $enableManual && in_array($mode, ['hybrid', 'manual']);
        ?>
            <h3 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 700; color: #1e293b;">Choose Verification Method</h3>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 28px; max-width: 520px; margin-left: auto; margin-right: auto;">
                Please select how you would like to complete your identity verification.
            </p>

            <?php if ($canDidit && $canManual): ?>
                <div class="row" style="text-align: left; margin-bottom: 10px;">
                    <!-- Option 1: Instant Didit AI -->
                    <div class="col-sm-6" style="margin-bottom: 18px;">
                        <div style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 22px 20px; height: 100%; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease; position: relative;" onmouseover="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 4px 12px rgba(59,130,246,0.1)';" onmouseout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none';">
                            <span style="position: absolute; top: -10px; right: 14px; background: #2563eb; color: #ffffff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.5px;">Recommended</span>
                            <div>
                                <div style="width: 46px; height: 46px; background: #eff6ff; color: #2563eb; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 14px;">
                                    <i class="fa fa-bolt"></i>
                                </div>
                                <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e293b;">Instant Verification</h4>
                                <p style="font-size: 13px; color: #64748b; margin-bottom: 18px; line-height: 1.4;">Automated biometric facial match and AI document scanning. Instant decision in 1-2 minutes.</p>
                            </div>
                            <a href="index.php?m=clientverification&action=start&method=didit" class="btn btn-primary btn-block" style="font-weight: 600; padding: 10px 16px; border-radius: 6px;">
                                <i class="fa fa-flash"></i> Verify with Didit AI &raquo;
                            </a>
                        </div>
                    </div>

                    <!-- Option 2: Manual Upload -->
                    <div class="col-sm-6" style="margin-bottom: 18px;">
                        <div style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 22px 20px; height: 100%; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease;" onmouseover="this.style.borderColor='#10b981'; this.style.boxShadow='0 4px 12px rgba(16,185,129,0.1)';" onmouseout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none';">
                            <div>
                                <div style="width: 46px; height: 46px; background: #f0fdf4; color: #16a34a; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 14px;">
                                    <i class="fa fa-cloud-upload"></i>
                                </div>
                                <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e293b;">Manual Upload</h4>
                                <p style="font-size: 13px; color: #64748b; margin-bottom: 18px; line-height: 1.4;">Upload photos of your National ID, Passport, or License for human review by our compliance team.</p>
                            </div>
                            <a href="index.php?m=clientverification&action=start&method=manual" class="btn btn-success btn-block" style="font-weight: 600; padding: 10px 16px; border-radius: 6px; background: #16a34a; border-color: #16a34a;">
                                <i class="fa fa-upload"></i> Upload Documents &raquo;
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif ($canDidit): ?>
                <div style="max-width: 420px; margin: 0 auto 20px auto; background: #f8fafc; border: 2px solid #3b82f6; border-radius: 10px; padding: 26px 20px; text-align: center;">
                    <div style="width: 54px; height: 54px; background: #eff6ff; color: #2563eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 16px auto;">
                        <i class="fa fa-bolt"></i>
                    </div>
                    <h4 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700; color: #1e293b;">Instant AI Verification</h4>
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 20px; line-height: 1.5;">Automated biometric facial match and document scanning. Fast and secure.</p>
                    <a href="index.php?m=clientverification&action=start&method=didit" class="btn btn-primary btn-lg" style="font-weight: 600; padding: 12px 32px; border-radius: 6px;">
                        <i class="fa fa-flash"></i> Start Instant Verification &raquo;
                    </a>
                </div>
            <?php else: ?>
                <div style="max-width: 420px; margin: 0 auto 20px auto; background: #f8fafc; border: 2px solid #10b981; border-radius: 10px; padding: 26px 20px; text-align: center;">
                    <div style="width: 54px; height: 54px; background: #f0fdf4; color: #16a34a; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 16px auto;">
                        <i class="fa fa-cloud-upload"></i>
                    </div>
                    <h4 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700; color: #1e293b;">Manual Document Upload</h4>
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 20px; line-height: 1.5;">Upload clear photos of your ID or Passport for review by our compliance team.</p>
                    <a href="index.php?m=clientverification&action=start&method=manual" class="btn btn-success btn-lg" style="font-weight: 600; padding: 12px 32px; border-radius: 6px; background: #16a34a; border-color: #16a34a;">
                        <i class="fa fa-upload"></i> Upload Documents &raquo;
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

