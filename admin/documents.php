<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$query = Capsule::table('mod_cv_documents')
    ->leftJoin('mod_cv_verifications', 'mod_cv_documents.verification_id', '=', 'mod_cv_verifications.id')
    ->leftJoin('tblclients', 'mod_cv_verifications.client_id', '=', 'tblclients.id')
    ->select('mod_cv_documents.*', 'mod_cv_verifications.status as vstatus', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.id as client_id');

if ($statusFilter) {
    $query->where('mod_cv_documents.status', $statusFilter);
}

if ($searchQuery) {
    $query->where(function ($q) use ($searchQuery) {
        $q->where('tblclients.firstname', 'like', "%{$searchQuery}%")
          ->orWhere('tblclients.lastname', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_documents.original_filename', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_documents.document_type', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_documents.verification_id', $searchQuery);
    });
}

$total = $query->count();
$docs = $query->orderByDesc('mod_cv_documents.id')
    ->forPage($page, $perPage)
    ->get();

cv_admin_header('documents', 'Documents', 'Manage and audit all uploaded KYC identity documents.');

$counts = [
    '' => Capsule::table('mod_cv_documents')->count(),
    'pending' => Capsule::table('mod_cv_documents')->where('status', 'pending')->count(),
    'approved' => Capsule::table('mod_cv_documents')->where('status', 'approved')->count(),
    'rejected' => Capsule::table('mod_cv_documents')->where('status', 'rejected')->count(),
];

$baseUrl = 'addonmodules.php?module=clientverification&action=documents'
    . ($statusFilter ? '&status=' . urlencode($statusFilter) : '')
    . ($searchQuery ? '&q=' . urlencode($searchQuery) : '');

?>

<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 18px 20px; margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $s => $label): 
                $isActive = ($statusFilter === $s);
                $btnClass = $isActive ? 'btn-primary' : 'btn-default';
            ?>
                <a class="btn <?php echo $btnClass; ?> btn-sm" href="addonmodules.php?module=clientverification&action=documents<?php echo $s ? '&status=' . urlencode($s) : ''; ?><?php echo $searchQuery ? '&q=' . urlencode($searchQuery) : ''; ?>" style="font-weight: 500;">
                    <?php echo htmlspecialchars($label); ?>
                    <span class="badge" style="background: <?php echo $isActive ? '#ffffff' : '#64748b'; ?>; color: <?php echo $isActive ? '#2563eb' : '#ffffff'; ?>; font-size: 11px; margin-left: 3px;">
                        <?php echo (int)($counts[$s] ?? 0); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="addonmodules.php" style="margin: 0; display: flex; gap: 6px;">
            <input type="hidden" name="module" value="clientverification">
            <input type="hidden" name="action" value="documents">
            <?php if ($statusFilter): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" class="form-control input-sm" placeholder="Search file, client, ID..." style="width: 220px;">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search"></i></button>
            <?php if ($searchQuery): ?>
                <a href="addonmodules.php?module=clientverification&action=documents<?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>" class="btn btn-default btn-sm" title="Clear Search"><i class="fa fa-times"></i></a>
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
                    <th style="font-size: 12px; color: #64748b;">Document Type</th>
                    <th style="font-size: 12px; color: #64748b;">Side</th>
                    <th style="font-size: 12px; color: #64748b;">File Info</th>
                    <th style="font-size: 12px; color: #64748b;">Status</th>
                    <th style="font-size: 12px; color: #64748b;">Uploaded At</th>
                    <th style="font-size: 12px; color: #64748b; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($docs->isEmpty()): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fa fa-file-o fa-2x" style="margin-bottom: 10px; display: block;"></i>
                            No documents found matching your filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($docs as $d):
                        $docStatusBadge = match($d->status) {
                            'approved' => '<span class="label label-success" style="font-size: 11px;">Approved</span>',
                            'rejected' => '<span class="label label-danger" style="font-size: 11px;">Rejected</span>',
                            default => '<span class="label label-warning" style="font-size: 11px;">Pending</span>',
                        };
                        $clientName = trim(($d->firstname ?? '') . ' ' . ($d->lastname ?? ''));
                    ?>
                        <tr>
                            <td><strong>#<?php echo (int) $d->id; ?></strong></td>
                            <td>
                                <strong><a href="clientssummary.php?userid=<?php echo (int) ($d->client_id ?? 0); ?>" target="_blank"><?php echo htmlspecialchars($clientName ?: 'Client #' . ($d->client_id ?? '?')); ?></a></strong>
                            </td>
                            <td>
                                <strong style="text-transform: capitalize; color: #1e293b;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $d->document_type)); ?>
                                </strong>
                            </td>
                            <td>
                                <span class="label label-default" style="text-transform: uppercase; font-size: 10px;">
                                    <?php echo htmlspecialchars($d->side ?: 'Front'); ?>
                                </span>
                            </td>
                            <td style="font-size: 12px; color: #64748b;">
                                <code><?php echo htmlspecialchars($d->original_filename); ?></code>
                                <div><?php echo round($d->file_size / 1024, 1); ?> KB <?php echo $d->encrypted ? '<span title="Encrypted" class="text-success"><i class="fa fa-lock"></i></span>' : ''; ?></div>
                            </td>
                            <td><?php echo $docStatusBadge; ?></td>
                            <td style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($d->created_at ?? $d->uploaded_at); ?></td>
                            <td style="text-align: right;">
                                <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $d->verification_id; ?>" class="btn btn-default btn-xs" title="View Verification Details">
                                    <i class="fa fa-id-card"></i> #<?php echo (int) $d->verification_id; ?>
                                </a>
                                <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $d->verification_id; ?>&download=<?php echo (int) $d->id; ?>" target="_blank" class="btn btn-primary btn-xs" title="Open Document">
                                    <i class="fa fa-external-link"></i> View
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

