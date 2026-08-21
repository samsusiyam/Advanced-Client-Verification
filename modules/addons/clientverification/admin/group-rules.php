<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check($_POST['cv_token'] ?? null)) {
    if (isset($_POST['add'])) {
        $gid = Sanitizer::int($_POST['group_id']);
        $req = in_array($_POST['requirement'], ['required', 'optional', 'not_required']) ? $_POST['requirement'] : 'required';
        if ($gid) {
            Capsule::table('mod_cv_group_rules')->updateOrInsert(
                ['group_id' => $gid],
                ['requirement' => $req, 'updated_at' => date('Y-m-d H:i:s')]
            );
            echo '<div class="alert alert-success">Group rule saved.</div>';
        }
    }
    if (isset($_POST['delete_id'])) {
        Capsule::table('mod_cv_group_rules')->where('id', Sanitizer::int($_POST['delete_id']))->delete();
    }
}

$rules = Capsule::table('mod_cv_group_rules')->get();
?>
<h2><?php echo Sanitizer::escape($_LANG['cv_group_rules']); ?></h2>
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;max-width:800px;">
<form method="post">
<?php echo Csrf::field(); ?>
<input type="hidden" name="add" value="1">
Client Group ID: <input type="text" name="group_id" class="form-control" style="width:120px;display:inline-block;">
Requirement:
<select name="requirement" class="form-control" style="width:160px;display:inline-block;">
<option value="required">Required</option>
<option value="optional">Optional</option>
<option value="not_required">Not Required</option>
</select>
<button type="submit" class="btn btn-primary">Add</button>
</form>
<table class="table table-bordered" style="margin-top:16px;width:100%;">
<thead><tr><th>Group</th><th>Requirement</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rules as $r):
    $gname = Capsule::table('tblclientgroups')->where('id', $r->group_id)->value('groupname'); ?>
<tr><td><?php echo Sanitizer::escape($gname ?? ('#' . $r->group_id)); ?></td>
<td><?php echo Sanitizer::escape($r->requirement); ?></td>
<td>
<form method="post" style="display:inline;"><?php echo Csrf::field(); ?>
<input type="hidden" name="delete_id" value="<?php echo Sanitizer::escape($r->id); ?>">
<button class="btn btn-danger btn-sm">Delete</button></form></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
