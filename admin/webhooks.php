<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

$successMsg = '';
$errorMsg = '';

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
            $successMsg = 'Webhook subscription added successfully.';
        } else {
            $errorMsg = 'Please enter a valid webhook endpoint URL (including https://).';
        }
    }
    if (isset($_POST['delete_id'])) {
        Capsule::table('mod_cv_webhook_configs')->where('id', Sanitizer::int($_POST['delete_id']))->delete();
        $successMsg = 'Webhook removed.';
    }
}

$configs = Capsule::table('mod_cv_webhook_configs')->orderByDesc('id')->get();

cv_admin_header('webhooks', 'Webhooks', 'Configure real-time outbound webhooks for third-party event dispatch.');

?>

<?php if ($successMsg): ?>
    <div class="alert alert-success" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($successMsg); ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-times-circle"></i> <?php echo htmlspecialchars($errorMsg); ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-5">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-plus-circle text-primary"></i> Add Outbound Webhook
            </h4>

            <form method="post">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="add" value="1">
                
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Trigger Event:</label>
                    <select name="event_type" class="form-control">
                        <option value="verification.approved">verification.approved (Client Approved)</option>
                        <option value="verification.rejected">verification.rejected (Client Rejected)</option>
                        <option value="verification.review_required">verification.review_required (Under Review)</option>
                        <option value="verification.created">verification.created (Started)</option>
                        <option value="verification.expired">verification.expired (Expired)</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Payload URL (HTTPS):</label>
                    <input type="url" name="url" class="form-control" placeholder="https://example.com/api/kyc-webhook" required>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Signing Secret (Optional):</label>
                    <input type="password" name="secret" class="form-control" placeholder="Shared HMAC secret">
                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Sent in <code>X-Signature-256</code> header.</div>
                </div>

                <button type="submit" class="btn btn-primary" style="font-weight: 600;">
                    <i class="fa fa-paper-plane"></i> Add Webhook
                </button>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 20px;">
            <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">Active Subscriptions (<?php echo count($configs); ?>)</h4>
            </div>

            <div class="table-responsive" style="margin: 0;">
                <table class="table table-hover" style="margin: 0;">
                    <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <tr>
                            <th style="font-size: 12px; color: #64748b;">Event</th>
                            <th style="font-size: 12px; color: #64748b;">Target URL</th>
                            <th style="font-size: 12px; color: #64748b;">Status</th>
                            <th style="font-size: 12px; color: #64748b; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($configs->isEmpty()): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 30px; color: #94a3b8;">
                                    No outbound webhooks registered yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($configs as $c): ?>
                                <tr>
                                    <td>
                                        <span class="label label-info" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($c->event_type); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code style="word-break: break-all;"><?php echo htmlspecialchars($c->url); ?></code>
                                        <?php if ($c->failure_count > 0): ?>
                                            <span class="label label-warning" style="font-size: 10px; margin-left: 4px;"><?php echo (int) $c->failure_count; ?> fails</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $c->active ? '<span class="text-success"><i class="fa fa-check-circle"></i> Active</span>' : '<span class="text-muted">Disabled</span>'; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this webhook?');">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="delete_id" value="<?php echo (int) $c->id; ?>">
                                            <button type="submit" class="btn btn-danger btn-xs" title="Remove webhook">
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

