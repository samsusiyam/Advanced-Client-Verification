<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$stats = [
    'total' => Capsule::table('mod_cv_verifications')->count(),
    'pending' => Capsule::table('mod_cv_verifications')->where('status', 'pending')->count(),
    'under_review' => Capsule::table('mod_cv_verifications')->where('status', 'under_review')->count(),
    'approved' => Capsule::table('mod_cv_verifications')->where('status', 'approved')->count(),
    'rejected' => Capsule::table('mod_cv_verifications')->where('status', 'rejected')->count(),
    'expired' => Capsule::table('mod_cv_verifications')->where('status', 'expired')->count(),
];

$diditApproved = Capsule::table('mod_cv_verifications')->where('verification_method', 'didit')->where('status', 'approved')->count();
$manualApproved = Capsule::table('mod_cv_verifications')->where('verification_method', 'manual')->where('status', 'approved')->count();
$manualReviews = Capsule::table('mod_cv_verifications')->where('manual_review_required', 1)->count();
$providerErrors = Capsule::table('mod_cv_verifications')->where('didit_decision', 'error')->count();

$recentVerifications = Capsule::table('mod_cv_verifications')
    ->leftJoin('tblclients', 'mod_cv_verifications.client_id', '=', 'tblclients.id')
    ->select('mod_cv_verifications.*', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email')
    ->orderByDesc('mod_cv_verifications.id')
    ->limit(10)
    ->get();

cv_admin_header('dashboard', 'Dashboard', 'Overview of client identity verifications, pending reviews, and KYC metrics.');

function cv_stat_card($title, $count, $icon, $borderColor, $iconColor)
{
    return '<div style="flex: 1; min-width: 170px; background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid ' . $borderColor . '; border-radius: 8px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div style="font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">' . htmlspecialchars($title) . '</div>
            <div style="font-size: 28px; font-weight: 700; color: #0f172a; margin-top: 4px;">' . number_format($count) . '</div>
        </div>
        <div style="font-size: 28px; color: ' . $iconColor . '; opacity: 0.85;">
            <i class="fa ' . $icon . '"></i>
        </div>
    </div>';
}
?>

<div style="display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 24px;">
    <?php
    echo cv_stat_card('Total', $stats['total'], 'fa-users', '#3b82f6', '#3b82f6');
    echo cv_stat_card('Pending', $stats['pending'], 'fa-clock-o', '#f59e0b', '#f59e0b');
    echo cv_stat_card('Under Review', $stats['under_review'], 'fa-eye', '#8b5cf6', '#8b5cf6');
    echo cv_stat_card('Approved', $stats['approved'], 'fa-check-circle', '#10b981', '#10b981');
    echo cv_stat_card('Rejected', $stats['rejected'], 'fa-times-circle', '#ef4444', '#ef4444');
    echo cv_stat_card('Expired', $stats['expired'], 'fa-hourglass-end', '#64748b', '#64748b');
    ?>
</div>

<div class="row" style="margin-bottom: 24px;">
    <div class="col-md-3">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">DIDIT AUTOMATED</div>
            <div style="font-size: 22px; font-weight: 700; color: #06b6d4; margin-top: 4px;"><?php echo number_format($diditApproved); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">MANUAL VERIFIED</div>
            <div style="font-size: 22px; font-weight: 700; color: #14b8a6; margin-top: 4px;"><?php echo number_format($manualApproved); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">MANUAL REVIEWS REQUIRED</div>
            <div style="font-size: 22px; font-weight: 700; color: #8b5cf6; margin-top: 4px;"><?php echo number_format($manualReviews); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">PROVIDER ERRORS</div>
            <div style="font-size: 22px; font-weight: 700; color: #ef4444; margin-top: 4px;"><?php echo number_format($providerErrors); ?></div>
        </div>
    </div>
</div>

<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 24px;">
    <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
        <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">Recent Verification Requests</h4>
        <a href="addonmodules.php?module=clientverification&action=verifications" class="btn btn-default btn-xs">View All &raquo;</a>
    </div>

    <div class="table-responsive" style="margin: 0;">
        <table class="table table-hover" style="margin: 0;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th style="font-size: 12px; color: #64748b;">ID</th>
                    <th style="font-size: 12px; color: #64748b;">Client</th>
                    <th style="font-size: 12px; color: #64748b;">Method</th>
                    <th style="font-size: 12px; color: #64748b;">Status</th>
                    <th style="font-size: 12px; color: #64748b;">Risk Level</th>
                    <th style="font-size: 12px; color: #64748b;">Submitted</th>
                    <th style="font-size: 12px; color: #64748b; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentVerifications->isEmpty()): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                            <i class="fa fa-info-circle fa-2x" style="margin-bottom: 8px; display: block;"></i>
                            No verification requests yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentVerifications as $row):
                        $statusBadge = match($row->status) {
                            'approved' => '<span class="label label-success" style="font-size: 11px; padding: 4px 8px;">Approved</span>',
                            'rejected' => '<span class="label label-danger" style="font-size: 11px; padding: 4px 8px;">Rejected</span>',
                            'under_review' => '<span class="label label-warning" style="font-size: 11px; padding: 4px 8px;">Under Review</span>',
                            'expired' => '<span class="label label-default" style="font-size: 11px; padding: 4px 8px;">Expired</span>',
                            default => '<span class="label label-info" style="font-size: 11px; padding: 4px 8px;">Pending</span>',
                        };

                        $riskBadge = match($row->risk_level) {
                            'high' => '<span style="color: #ef4444; font-weight: 600;"><i class="fa fa-circle"></i> High</span>',
                            'medium' => '<span style="color: #f59e0b; font-weight: 600;"><i class="fa fa-circle"></i> Med</span>',
                            default => '<span style="color: #10b981; font-weight: 600;"><i class="fa fa-circle"></i> Low</span>',
                        };
                    ?>
                        <tr>
                            <td><strong>#<?php echo (int) $row->id; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars(trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')) ?: 'Client #' . $row->client_id); ?></strong>
                                <div style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($row->email ?? ''); ?></div>
                            </td>
                            <td><span style="text-transform: capitalize; background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 12px;"><?php echo htmlspecialchars($row->verification_method); ?></span></td>
                            <td><?php echo $statusBadge; ?></td>
                            <td><?php echo $riskBadge; ?> <span style="font-size: 11px; color: #94a3b8;">(<?php echo htmlspecialchars($row->risk_score); ?>)</span></td>
                            <td style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($row->submitted_at ?? $row->created_at); ?></td>
                            <td style="text-align: right;">
                                <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $row->id; ?>" class="btn btn-default btn-xs" style="font-weight: 600;">
                                    <i class="fa fa-search"></i> Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

