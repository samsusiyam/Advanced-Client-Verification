$feedbackMessage = '';
$feedbackType = 'success';

// Handle manual prune action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cv_prune_action']) && \ClientVerification\Security\Csrf::check($_POST['cv_token'] ?? null)) {
    $pruneDays = (int) ($_POST['prune_days'] ?? 0);
    $deleted = cv_prune_audit_logs($pruneDays > 0 ? $pruneDays : null);
    if ($pruneDays > 0) {
        $feedbackMessage = "Successfully pruned {$deleted} audit log entries older than {$pruneDays} days.";
    } else {
        $feedbackMessage = "Prune executed based on global settings. {$deleted} old records removed.";
    }
}

// Handle clear all logs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cv_clear_all_logs']) && \ClientVerification\Security\Csrf::check($_POST['cv_token'] ?? null)) {
    $deleted = Capsule::table('mod_cv_audit_logs')->delete();
    $feedbackMessage = "All {$deleted} audit log records have been cleared.";
}

$retentionDays = (int) cv_setting('audit_log_retention_days', 0);
if ($retentionDays > 0) {
    cv_prune_audit_logs();
}

$actionFilter = trim($_GET['log_action'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;

$query = Capsule::table('mod_cv_audit_logs')
    ->leftJoin('tbladmins', 'mod_cv_audit_logs.admin_id', '=', 'tbladmins.id')
    ->select('mod_cv_audit_logs.*', 'tbladmins.username', 'tbladmins.firstname', 'tbladmins.lastname');

if ($actionFilter) {
    $query->where('mod_cv_audit_logs.action', $actionFilter);
}

if ($searchQuery) {
    $query->where(function ($q) use ($searchQuery) {
        $q->where('mod_cv_audit_logs.note', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_audit_logs.verification_id', $searchQuery)
          ->orWhere('mod_cv_audit_logs.ip', 'like', "%{$searchQuery}%")
          ->orWhere('tbladmins.username', 'like', "%{$searchQuery}%");
    });
}

$total = $query->count();
$rows = $query->orderByDesc('mod_cv_audit_logs.id')
    ->forPage($page, $perPage)
    ->get();

$allActions = Capsule::table('mod_cv_audit_logs')->distinct()->pluck('action')->toArray();

cv_admin_header('audit-logs', 'Audit Logs', 'Complete audit trail of all staff decisions, system actions, and KYC events.');

$baseUrl = 'addonmodules.php?module=clientverification&action=audit-logs'
    . ($actionFilter ? '&log_action=' . urlencode($actionFilter) : '')
    . ($searchQuery ? '&q=' . urlencode($searchQuery) : '');

?>

<?php if ($feedbackMessage): ?>
    <div class="alert alert-<?php echo $feedbackType; ?>" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($feedbackMessage); ?>
    </div>
<?php endif; ?>

<!-- Filter & Prune Toolbar -->
<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 18px 20px; margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <form method="get" action="addonmodules.php" style="margin: 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <input type="hidden" name="module" value="clientverification">
            <input type="hidden" name="action" value="audit-logs">
            
            <select name="log_action" class="form-control input-sm" style="width: 180px;" onchange="this.form.submit()">
                <option value="">-- All Actions --</option>
                <?php foreach ($allActions as $act): ?>
                    <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($act); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" class="form-control input-sm" placeholder="Search notes, ID, IP, admin..." style="width: 220px;">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search"></i> Search</button>
            <?php if ($actionFilter || $searchQuery): ?>
                <a href="addonmodules.php?module=clientverification&action=audit-logs" class="btn btn-default btn-sm" title="Clear Filters"><i class="fa fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>

        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 12px; color: #64748b;">
                Retention: <strong><?php echo $retentionDays > 0 ? $retentionDays . ' Days (Auto-Pruning Active)' : 'Keep Forever (Never Auto-Delete)'; ?></strong>
            </span>
            <button type="button" class="btn btn-default btn-sm" onclick="document.getElementById('cv_prune_modal').style.display='block';">
                <i class="fa fa-trash text-danger"></i> Prune / Clear Logs
            </button>
        </div>
    </div>
</div>

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
                            No audit log entries found matching criteria.
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

<!-- Modal Dialog for Pruning Logs -->
<div id="cv_prune_modal" style="display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); overflow: auto;">
    <div style="background: #ffffff; width: 480px; max-width: 90%; margin: 80px auto; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="background: #f8fafc; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b;"><i class="fa fa-trash text-danger"></i> Prune Compliance Audit Logs</h4>
            <button type="button" onclick="document.getElementById('cv_prune_modal').style.display='none';" style="background: none; border: none; font-size: 18px; cursor: pointer;">&times;</button>
        </div>

        <div style="padding: 20px;">
            <form method="post" action="<?php echo htmlspecialchars($baseUrl); ?>">
                <?php echo \ClientVerification\Security\Csrf::field(); ?>
                
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Prune logs older than:</label>
                    <select name="prune_days" class="form-control">
                        <option value="30">30 Days</option>
                        <option value="60">60 Days</option>
                        <option value="90">90 Days</option>
                        <option value="180">180 Days</option>
                        <option value="365">1 Year (365 Days)</option>
                    </select>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <button type="submit" name="cv_prune_action" value="1" class="btn btn-warning" onclick="return confirm('Prune audit logs older than selected days?');">
                        <i class="fa fa-scissors"></i> Prune Old Logs
                    </button>
                    <button type="submit" name="cv_clear_all_logs" value="1" class="btn btn-danger btn-sm" onclick="return confirm('CAUTION: Are you sure you want to permanently delete ALL audit logs?');">
                        <i class="fa fa-eraser"></i> Clear All Logs
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

