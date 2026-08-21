<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;

$query = Capsule::table('mod_cv_audit_logs')
    ->leftJoin('tbladmins', 'mod_cv_audit_logs.admin_id', '=', 'tbladmins.id')
    ->select('mod_cv_audit_logs.*', 'tbladmins.username', 'tbladmins.firstname', 'tbladmins.lastname');

$total = $query->count();
$rows = $query->orderByDesc('mod_cv_audit_logs.id')
    ->forPage($page, $perPage)
    ->get();

cv_admin_header('audit-logs', 'Audit Logs', 'Complete audit trail of all staff decisions, system actions, and KYC events.');

$baseUrl = 'addonmodules.php?module=clientverification&action=audit-logs';

?>

<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 24px;">
    <div class="table-responsive" style="margin: 0;">
        <table class="table table-hover" style="margin: 0;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th style="font-size: 12px; color: #64748b;">ID</th>
                    <th style="font-size: 12px; color: #64748b;">Verification ID</th>
                    <th style="font-size: 12px; color: #64748b;">User / Actor</th>
                    <th style="font-size: 12px; color: #64748b;">Action</th>
                    <th style="font-size: 12px; color: #64748b;">Notes / Details</th>
                    <th style="font-size: 12px; color: #64748b;">IP Address</th>
                    <th style="font-size: 12px; color: #64748b;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows->isEmpty()): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fa fa-history fa-2x" style="margin-bottom: 8px; display: block;"></i>
                            No audit log entries recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): 
                        $actor = 'System';
                        if ($r->admin_id > 0) {
                            $actor = htmlspecialchars($r->username ?: ($r->firstname . ' ' . $r->lastname)) . ' (Admin #' . (int)$r->admin_id . ')';
                        } elseif ($r->admin_id === -1) {
                            $actor = 'API / Token';
                        }
                    ?>
                        <tr>
                            <td><strong>#<?php echo (int) $r->id; ?></strong></td>
                            <td>
                                <?php if ($r->verification_id): ?>
                                    <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $r->verification_id; ?>" class="btn btn-default btn-xs">
                                        #<?php echo (int) $r->verification_id; ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><strong style="color: #334155;"><?php echo $actor; ?></strong></td>
                            <td>
                                <span style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; color: #1e293b;">
                                    <?php echo htmlspecialchars($r->action); ?>
                                </span>
                            </td>
                            <td style="font-size: 12px; color: #475569;"><?php echo htmlspecialchars($r->note ?: '-'); ?></td>
                            <td><code><?php echo htmlspecialchars($r->ip ?: '-'); ?></code></td>
                            <td style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($r->created_at); ?></td>
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

