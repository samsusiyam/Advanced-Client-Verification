<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$query = Capsule::table('mod_cv_verifications')
    ->leftJoin('tblclients', 'mod_cv_verifications.client_id', '=', 'tblclients.id')
    ->select('mod_cv_verifications.*', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email');

if ($statusFilter) {
    $query->where('mod_cv_verifications.status', $statusFilter);
}

if ($searchQuery) {
    $query->where(function ($q) use ($searchQuery) {
        $q->where('tblclients.firstname', 'like', "%{$searchQuery}%")
          ->orWhere('tblclients.lastname', 'like', "%{$searchQuery}%")
          ->orWhere('tblclients.email', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_verifications.client_id', $searchQuery)
          ->orWhere('mod_cv_verifications.client_ref', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_verifications.id', $searchQuery);
    });
}

$total = $query->count();
$rows = $query->orderByDesc('mod_cv_verifications.id')
    ->forPage($page, $perPage)
    ->get();

cv_admin_header('verifications', 'Verifications', 'List and review all KYC verification requests.');

$counts = [
    '' => Capsule::table('mod_cv_verifications')->count(),
    'pending' => Capsule::table('mod_cv_verifications')->where('status', 'pending')->count(),
    'under_review' => Capsule::table('mod_cv_verifications')->where('status', 'under_review')->count(),
    'approved' => Capsule::table('mod_cv_verifications')->where('status', 'approved')->count(),
    'rejected' => Capsule::table('mod_cv_verifications')->where('status', 'rejected')->count(),
    'expired' => Capsule::table('mod_cv_verifications')->where('status', 'expired')->count(),
];

$baseUrl = 'addonmodules.php?module=clientverification&action=verifications'
    . ($statusFilter ? '&status=' . urlencode($statusFilter) : '')
    . ($searchQuery ? '&q=' . urlencode($searchQuery) : '');

?>

<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 18px 20px; margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <?php foreach (['' => 'All', 'pending' => 'Pending', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $s => $label): 
                $isActive = ($statusFilter === $s);
                $btnClass = $isActive ? 'btn-primary' : 'btn-default';
            ?>
                <a class="btn <?php echo $btnClass; ?> btn-sm" href="addonmodules.php?module=clientverification&action=verifications<?php echo $s ? '&status=' . urlencode($s) : ''; ?><?php echo $searchQuery ? '&q=' . urlencode($searchQuery) : ''; ?>" style="font-weight: 500;">
                    <?php echo htmlspecialchars($label); ?>
                    <span class="badge" style="background: <?php echo $isActive ? '#ffffff' : '#64748b'; ?>; color: <?php echo $isActive ? '#2563eb' : '#ffffff'; ?>; font-size: 11px; margin-left: 3px;">
                        <?php echo (int)($counts[$s] ?? 0); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="addonmodules.php" style="margin: 0; display: flex; gap: 6px;">
            <input type="hidden" name="module" value="clientverification">
            <input type="hidden" name="action" value="verifications">
            <?php if ($statusFilter): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" class="form-control input-sm" placeholder="Search name, email, ID..." style="width: 220px;">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search"></i></button>
            <?php if ($searchQuery): ?>
                <a href="addonmodules.php?module=clientverification&action=verifications<?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>" class="btn btn-default btn-sm" title="Clear Search"><i class="fa fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 24px;">
    <div class="table-responsive" style="margin: 0;">
        <table class="table table-hover" style="margin: 0;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th style="font-size: 12px; color: #64748b;">ID</th>
                    <th style="font-size: 12px; color: #64748b;">Client</th>
                    <th style="font-size: 12px; color: #64748b;">Method</th>
                    <th style="font-size: 12px; color: #64748b;">Status</th>
                    <th style="font-size: 12px; color: #64748b;">Risk Assessment</th>
                    <th style="font-size: 12px; color: #64748b;">Submitted</th>
                    <th style="font-size: 12px; color: #64748b; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows->isEmpty()): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fa fa-search fa-2x" style="margin-bottom: 10px; display: block;"></i>
                            No verifications found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $statusBadge = match($row->status) {
                            'approved' => '<span class="label label-success" style="font-size: 11px; padding: 4px 8px;">Approved</span>',
                            'rejected' => '<span class="label label-danger" style="font-size: 11px; padding: 4px 8px;">Rejected</span>',
                            'under_review' => '<span class="label label-warning" style="font-size: 11px; padding: 4px 8px;">Under Review</span>',
                            'expired' => '<span class="label label-default" style="font-size: 11px; padding: 4px 8px;">Expired</span>',
                            default => '<span class="label label-info" style="font-size: 11px; padding: 4px 8px;">Pending</span>',
                        };

                        $riskBadge = match($row->risk_level) {
                            'high' => '<span class="label label-danger" style="font-size: 11px; padding: 3px 6px;">High (' . htmlspecialchars($row->risk_score) . ')</span>',
                            'medium' => '<span class="label label-warning" style="font-size: 11px; padding: 3px 6px;">Medium (' . htmlspecialchars($row->risk_score) . ')</span>',
                            default => '<span class="label label-success" style="font-size: 11px; padding: 3px 6px;">Low (' . htmlspecialchars($row->risk_score) . ')</span>',
                        };
                    ?>
                        <tr>
                            <td><strong>#<?php echo (int) $row->id; ?></strong></td>
                            <td>
                                <strong><a href="clientssummary.php?userid=<?php echo (int) $row->client_id; ?>" target="_blank"><?php echo htmlspecialchars(trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')) ?: 'Client #' . $row->client_id); ?></a></strong>
                                <div style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($row->email ?? ''); ?></div>
                            </td>
                            <td>
                                <span style="text-transform: capitalize; background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    <?php echo htmlspecialchars($row->verification_method); ?>
                                </span>
                            </td>
                            <td><?php echo $statusBadge; ?></td>
                            <td><?php echo $riskBadge; ?></td>
                            <td style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($row->submitted_at ?? $row->created_at); ?></td>
                            <td style="text-align: right;">
                                <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $row->id; ?>" class="btn btn-primary btn-sm" style="font-weight: 600;">
                                    <i class="fa fa-eye"></i> Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="padding: 0 20px 16px 20px;">
        <?php echo cv_render_pagination($total, $perPage, $page, $baseUrl); ?>
    </div>
</div>

