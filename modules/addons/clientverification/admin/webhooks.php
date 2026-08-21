<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check($_POST['cv_token'] ?? null)) {
    if (isset($_POST['add'])) {
        $event = in_array($_POST['event_type'], ['verification.created', 'verification.approved', 'verification.rejected', 'verification.review_required', 'verification.expired']) ? $_POST['event_type'] : 'verification.approved';
        $url = filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL);
        $secret = Sanitizer::cleanString($_POST['secret'] ?? '', 255);
        if ($url) {
            Capsule::table('mod_cv_webhook_configs')->insert([
                'event_type' => $event,
                'url' => $url,
                'secret' => cv_encrypt_credentials($secret),
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            echo '<div class="alert alert-success">Webhook added.</div>';
        } else {
            echo '<div class="alert alert-danger">Invalid URL.</div>';
        }
    }
    if (isset($_POST['delete_id'])) {
        Capsule::table('mod_cv_webhook_configs')->where('id', Sanitizer::int($_POST['delete_id']))->delete();
    }
}

$configs = Capsule::table('mod_cv_webhook_configs')->get();
?>
<h2><?php echo Sanitizer::escape($_LANG['cv_webhooks']); ?></h2>
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;max-width:900px;">
<form method="post">
<?php echo Csrf::field(); ?>
<input type="hidden" name="add" value="1">
Event:
<select name="event_type" class="form-control" style="width:220px;display:inline-block;">
<option value="verification.created">verification.created</option>
<option value="verification.approved">verification.approved</option>
<option value="verification.rejected">verification.rejected</option>
<option value="verification.review_required">verification.review_required</option>
<option value="verification.expired">verification.expired</option>
</select>
URL: <input type="text" name="url" class="form-control" style="width:300px;display:inline-block;">
Secret: <input type="text" name="secret" class="form-control" style="width:160px;display:inline-block;">
<button type="submit" class="btn btn-primary">Add</button>
</form>
<table class="table table-bordered" style="margin-top:16px;width:100%;">
<thead><tr><th>Event</th><th>URL</th><th>Active</th><th>Failures</th><th>Action</th></tr></thead><tbody>
<?php foreach ($configs as $c): ?>
<tr><td><?php echo Sanitizer::escape($c->event_type); ?></td>
<td><?php echo Sanitizer::escape($c->url); ?></td>
<td><?php echo $c->active ? 'Yes' : 'No'; ?></td>
<td><?php echo Sanitizer::escape($c->failure_count); ?></td>
<td>
<form method="post" style="display:inline;"><?php echo Csrf::field(); ?>
<input type="hidden" name="delete_id" value="<?php echo Sanitizer::escape($c->id); ?>">
<button class="btn btn-danger btn-sm">Delete</button></form></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
