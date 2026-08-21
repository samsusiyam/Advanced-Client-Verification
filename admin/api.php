<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;
use ClientVerification\Api\TokenAuth;

$adminId = (int) ($_SESSION['adminid'] ?? 0);

$scopesAvailable = [
    'read' => 'Read verifications (GET /api/v1/verifications)',
    'write' => 'Manage / Approve / Reject (POST /api/v1/verifications)',
    '*' => 'Full Administrative Access',
];

// Handle create / revoke / activate / delete
$generatedToken = null;
$feedbackMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check($_POST['cv_token'] ?? null)) {
    $op = $_POST['op'] ?? '';
    if ($op === 'create') {
        $name = Sanitizer::cleanString($_POST['name'] ?? '', 100);
        $scopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
            ? array_values(array_intersect($_POST['scopes'], array_keys($scopesAvailable)))
            : [];
        if (empty($scopes)) {
            $scopes = ['read'];
        }
        $expires = !empty($_POST['expires_at']) ? Sanitizer::cleanString($_POST['expires_at'], 20) : null;
        $rateLimit = max(1, (int) ($_POST['rate_limit'] ?? 60));
        $raw = cv_random_token(32);
        Capsule::table('mod_cv_api_tokens')->insert([
            'name' => $name ?: 'API Token',
            'token_hash' => TokenAuth::hash($raw),
            'scopes' => json_encode($scopes),
            'active' => 1,
            'expires_at' => $expires,
            'rate_limit' => $rateLimit,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        cv_log_audit(0, 'api_token_created', $adminId, $name);
        $generatedToken = $raw;
        $feedbackMsg = 'New API token generated successfully.';
    } elseif ($op === 'revoke' && is_numeric($_POST['id'] ?? '')) {
        Capsule::table('mod_cv_api_tokens')->where('id', (int) $_POST['id'])
            ->update(['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit(0, 'api_token_revoked', $adminId, (int) $_POST['id']);
        $feedbackMsg = 'Token revoked.';
    } elseif ($op === 'activate' && is_numeric($_POST['id'] ?? '')) {
        Capsule::table('mod_cv_api_tokens')->where('id', (int) $_POST['id'])
            ->update(['active' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit(0, 'api_token_activated', $adminId, (int) $_POST['id']);
        $feedbackMsg = 'Token activated.';
    } elseif ($op === 'delete' && is_numeric($_POST['id'] ?? '')) {
        Capsule::table('mod_cv_api_tokens')->where('id', (int) $_POST['id'])->delete();
        cv_log_audit(0, 'api_token_deleted', $adminId, (int) $_POST['id']);
        $feedbackMsg = 'Token deleted permanently.';
    }
}

$tokens = Capsule::table('mod_cv_api_tokens')->orderByDesc('id')->get();

cv_admin_header('api', 'API Tokens', 'Manage REST API access tokens and rate limits for external integrations.');

?>

<?php if ($generatedToken): ?>
    <div class="alert alert-success" style="border-radius: 8px; margin-bottom: 20px; background: #ecfdf5; border-color: #10b981; color: #065f46;">
        <h4 style="margin: 0 0 8px 0; font-weight: 700; color: #065f46;"><i class="fa fa-key"></i> New API Token Generated!</h4>
        <p style="margin-bottom: 10px;">Please copy your token now. It will not be shown again:</p>
        <div style="background: #ffffff; border: 1px solid #6ee7b7; border-radius: 6px; padding: 12px 14px; font-family: monospace; font-size: 14px; color: #047857; word-break: break-all;">
            <?php echo htmlspecialchars($generatedToken); ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($feedbackMsg && !$generatedToken): ?>
    <div class="alert alert-info" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-info-circle"></i> <?php echo htmlspecialchars($feedbackMsg); ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-5">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-plus-circle text-primary"></i> Create New API Token
            </h4>

            <form method="post">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="op" value="create">
                
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Token Label / Description:</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Mobile App backend" required>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Access Scopes:</label>
                    <?php foreach ($scopesAvailable as $s => $label): ?>
                        <div class="checkbox" style="margin-top: 4px; margin-bottom: 4px;">
                            <label style="font-size: 13px; color: #475569;">
                                <input type="checkbox" name="scopes[]" value="<?php echo htmlspecialchars($s); ?>" <?php echo $s === 'read' ? 'checked' : ''; ?>>
                                <strong><?php echo htmlspecialchars($s); ?></strong> - <?php echo htmlspecialchars($label); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Rate Limit (req/min):</label>
                    <input type="number" name="rate_limit" class="form-control" value="60" min="1" max="1000">
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Expires At (Optional):</label>
                    <input type="date" name="expires_at" class="form-control">
                </div>

                <button type="submit" class="btn btn-primary" style="font-weight: 600;">
                    <i class="fa fa-key"></i> Generate Token
                </button>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 20px;">
            <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">Active API Tokens (<?php echo count($tokens); ?>)</h4>
            </div>

            <div class="table-responsive" style="margin: 0;">
                <table class="table table-hover" style="margin: 0;">
                    <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <tr>
                            <th style="font-size: 12px; color: #64748b;">Token Name</th>
                            <th style="font-size: 12px; color: #64748b;">Scopes</th>
                            <th style="font-size: 12px; color: #64748b;">Requests</th>
                            <th style="font-size: 12px; color: #64748b;">Status</th>
                            <th style="font-size: 12px; color: #64748b; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tokens->isEmpty()): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">
                                    No API tokens configured yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tokens as $t):
                                $sc = json_decode($t->scopes, true);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($t->name); ?></strong>
                                        <div style="font-size: 11px; color: #64748b;">Limit: <?php echo (int) $t->rate_limit; ?> req/min</div>
                                    </td>
                                    <td>
                                        <?php if (is_array($sc)): ?>
                                            <?php foreach ($sc as $scope): ?>
                                                <span class="label label-default" style="font-size: 10px;"><?php echo htmlspecialchars($scope); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($t->request_count); ?></td>
                                    <td>
                                        <?php echo $t->active ? '<span class="text-success"><i class="fa fa-check-circle"></i> Active</span>' : '<span class="text-danger"><i class="fa fa-ban"></i> Revoked</span>'; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ($t->active): ?>
                                            <form method="post" style="display: inline;">
                                                <?php echo Csrf::field(); ?>
                                                <input type="hidden" name="op" value="revoke">
                                                <input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
                                                <button type="submit" class="btn btn-warning btn-xs" title="Revoke">Revoke</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display: inline;">
                                                <?php echo Csrf::field(); ?>
                                                <input type="hidden" name="op" value="activate">
                                                <input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
                                                <button type="submit" class="btn btn-success btn-xs" title="Activate">Enable</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this API token permanently?');">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="op" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
                                            <button type="submit" class="btn btn-danger btn-xs" title="Delete"><i class="fa fa-trash"></i></button>
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

