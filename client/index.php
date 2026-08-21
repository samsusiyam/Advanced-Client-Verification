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

        <?php elseif ($status === 'rejected'): ?>
            <div style="width: 72px; height: 72px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; font-size: 32px;">
                <i class="fa fa-times"></i>
            </div>
            <h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #991b1b;">Verification Unsuccessful</h3>
            <p style="color: #4b5563; font-size: 14px; margin-bottom: 24px;">Your previous verification could not be approved. Please review your details and submit new documents.</p>
            <a href="index.php?m=clientverification&action=start" class="btn btn-primary btn-lg" style="font-weight: 600; padding: 12px 28px; border-radius: 6px;">
                <i class="fa fa-refresh"></i> Try Again
            </a>

        <?php else: ?>
            <div style="width: 72px; height: 72px; background: #eff6ff; color: #2563eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; font-size: 32px;">
                <i class="fa fa-id-card-o"></i>
            </div>
            <h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #1e293b;">Verification Required</h3>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 24px; max-width: 480px; margin-left: auto; margin-right: auto;">
                To comply with regulatory standards and ensure account security, please complete your one-time identity verification.
            </p>
            <a href="index.php?m=clientverification&action=start" class="btn btn-primary btn-lg" style="font-weight: 600; padding: 12px 32px; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">
                <i class="fa fa-arrow-right"></i> Start Identity Verification
            </a>
        <?php endif; ?>
    </div>
</div>

