<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check($_POST['cv_token'] ?? null)) {
    if (isset($_POST['add'])) {
        $gid = Sanitizer::int($_POST['group_id']);
        $req = in_array($_POST['requirement'], ['required', 'optional', 'not_required']) ? $_POST['requirement'] : 'required';
        if ($gid) {
            Capsule::table('mod_cv_group_rules')->updateOrInsert(
                ['group_id' => $gid],
                ['requirement' => $req, 'updated_at' => date('Y-m-d H:i:s')]
            );
            $successMsg = 'Client group verification rule saved successfully.';
        }
    }
    if (isset($_POST['delete_id'])) {
        Capsule::table('mod_cv_group_rules')->where('id', Sanitizer::int($_POST['delete_id']))->delete();
        $successMsg = 'Client group rule removed.';
    }
}

$allGroups = [];
try {
    $allGroups = Capsule::table('tblclientgroups')->orderBy('groupname')->get();
} catch (\Exception $e) {}

$rules = Capsule::table('mod_cv_group_rules')
    ->leftJoin('tblclientgroups', 'mod_cv_group_rules.group_id', '=', 'tblclientgroups.id')
    ->select('mod_cv_group_rules.*', 'tblclientgroups.groupname', 'tblclientgroups.groupcolour')
    ->get();

cv_admin_header('group-rules', 'Group Rules', 'Specify verification requirements based on client groups.');

?>

<?php if ($successMsg): ?>
    <div class="alert alert-success" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($successMsg); ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-5">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-plus-circle text-primary"></i> Add / Update Group Rule
            </h4>

            <form method="post">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="add" value="1">
                
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Select Client Group:</label>
                    <select name="group_id" class="form-control" required style="width: 100%;">
                        <option value="">-- Choose a Client Group --</option>
                        <?php foreach ($allGroups as $g): ?>
                            <option value="<?php echo (int) $g->id; ?>">
                                <?php echo htmlspecialchars($g->groupname . ' (ID: ' . $g->id . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">KYC Verification Requirement:</label>
                    <select name="requirement" class="form-control">
                        <option value="required">Required (All clients in group must verify)</option>
                        <option value="optional">Optional (Allow checkout, prompt verification)</option>
                        <option value="not_required">Not Required (Exempt group from KYC)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="font-weight: 600;">
                    <i class="fa fa-save"></i> Save Group Rule
                </button>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 20px;">
            <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">Configured Group Rules (<?php echo count($rules); ?>)</h4>
            </div>

            <div class="table-responsive" style="margin: 0;">
                <table class="table table-hover" style="margin: 0;">
                    <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <tr>
                            <th style="font-size: 12px; color: #64748b;">Client Group</th>
                            <th style="font-size: 12px; color: #64748b;">Requirement</th>
                            <th style="font-size: 12px; color: #64748b; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rules->isEmpty()): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 30px; color: #94a3b8;">
                                    No group-specific rules yet. Global module settings apply to all client groups.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rules as $r):
                                $reqBadge = match($r->requirement) {
                                    'required' => '<span class="label label-danger">Required</span>',
                                    'optional' => '<span class="label label-info">Optional</span>',
                                    default => '<span class="label label-default">Not Required</span>',
                                };
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r->groupname ?? ('Group #' . $r->group_id)); ?></strong>
                                    </td>
                                    <td><?php echo $reqBadge; ?></td>
                                    <td style="text-align: right;">
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this group rule?');">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="delete_id" value="<?php echo (int) $r->id; ?>">
                                            <button type="submit" class="btn btn-danger btn-xs" title="Remove rule">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

