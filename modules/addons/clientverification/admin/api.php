<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;
use ClientVerification\Api\TokenAuth;

$adminId = (int) ($_SESSION['adminid'] ?? 0);

$scopesAvailable = [
    'read' => 'Read verifications (GET)',
    'write' => 'Create / approve / reject verifications (POST)',
    '*' => 'Full access (read + write)',
];

// Handle create / revoke / activate / delete.
$generatedToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check($_POST['cv_token'] ?? null)) {
    $op = $_POST['op'] ?? '';
    if ($op === 'create') {
        $name = Sanitizer::cleanString($_POST['name'] ?? '', 100);
        $scopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
            ? array_values(array_intersect($_POST['scopes'], array_keys($scopesAvailable)))
            : [];
        if (empty($scopes)) {
            $scopes = ['verify:read'];
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
    } elseif ($op === 'revoke' && is_numeric($_POST['id'] ?? '')) {
        Capsule::table('mod_cv_api_tokens')->where('id', (int) $_POST['id'])
            ->update(['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit(0, 'api_token_revoked', $adminId, (int) $_POST['id']);
    } elseif ($op === 'activate' && is_numeric($_POST['id'] ?? '')) {
        Capsule::table('mod_cv_api_tokens')->where('id', (int) $_POST['id'])
            ->update(['active' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit(0, 'api_token_activated', $adminId, (int) $_POST['id']);
    } elseif ($op === 'delete' && is_numeric($_POST['id'] ?? '')) {
        Capsule::table('mod_cv_api_tokens')->where('id', (int) $_POST['id'])->delete();
        cv_log_audit(0, 'api_token_deleted', $adminId, (int) $_POST['id']);
    }
}

$tokens = Capsule::table('mod_cv_api_tokens')->orderByDesc('id')->get();

echo '<h2>' . Sanitizer::escape($_LANG['cv_api_tokens']) . '</h2>';

if ($generatedToken) {
    echo '<div class="alert alert-success">';
    echo Sanitizer::escape($_LANG['cv_api_generated']) . '<br>';
    echo '<code style="word-break:break-all;">' . Sanitizer::escape($generatedToken) . '</code><br>';
    echo '<em>' . Sanitizer::escape($_LANG['cv_api_copy']) . '</em>';
    echo '</div>';
}

// Create form.
echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;max-width:700px;">';
echo '<h4>' . Sanitizer::escape($_LANG['cv_api_new_token']) . '</h4>';
echo '<form method="post">';
echo Csrf::field();
echo '<input type="hidden" name="op" value="create">';
echo '<div class="form-group"><label>' . Sanitizer::escape($_LANG['cv_api_name']) . '</label>';
echo '<input type="text" name="name" class="form-control" maxlength="100" required></div>';
echo '<div class="form-group"><label>' . Sanitizer::escape($_LANG['cv_api_scopes']) . '</label><br>';
foreach ($scopesAvailable as $s => $label) {
    echo '<label style="margin-right:14px;"><input type="checkbox" name="scopes[]" value="' . Sanitizer::escape($s) . '"> ' . Sanitizer::escape($label) . '</label><br>';
}
echo '</div>';
echo '<div class="form-group"><label>' . Sanitizer::escape($_LANG['cv_api_expires']) . ' (YYYY-MM-DD, optional)</label>';
echo '<input type="text" name="expires_at" class="form-control" placeholder="2030-01-01"></div>';
echo '<div class="form-group"><label>' . Sanitizer::escape($_LANG['cv_api_rate_limit']) . '</label>';
echo '<input type="text" name="rate_limit" class="form-control" value="60"></div>';
echo '<button type="submit" class="btn btn-primary">' . Sanitizer::escape($_LANG['cv_api_create']) . '</button>';
echo '</form></div>';

// List tokens.
echo '<div style="margin-top:16px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">';
echo '<table class="table table-bordered" style="width:100%;">';
echo '<thead><tr><th>ID</th><th>' . Sanitizer::escape($_LANG['cv_api_name']) . '</th><th>' . Sanitizer::escape($_LANG['cv_api_scopes']) . '</th><th>Active</th><th>Expires</th><th>Last Used</th><th>Requests</th><th>Action</th></tr></thead><tbody>';
foreach ($tokens as $t) {
    echo '<tr>';
    echo '<td>' . Sanitizer::escape($t->id) . '</td>';
    echo '<td>' . Sanitizer::escape($t->name) . '</td>';
    $sc = json_decode($t->scopes, true);
    echo '<td>' . Sanitizer::escape(is_array($sc) ? implode(', ', $sc) : '') . '</td>';
    echo '<td>' . ($t->active ? 'Yes' : 'No') . '</td>';
    echo '<td>' . Sanitizer::escape($t->expires_at ?? '-') . '</td>';
    echo '<td>' . Sanitizer::escape($t->last_used_at ?? '-') . '</td>';
    echo '<td>' . Sanitizer::escape($t->request_count) . '</td>';
    echo '<td>';
    if ($t->active) {
        echo '<form method="post" style="display:inline;"><input type="hidden" name="cv_token" value="' . Csrf::token() . '"><input type="hidden" name="op" value="revoke"><input type="hidden" name="id" value="' . Sanitizer::escape($t->id) . '"><button type="submit" class="btn btn-warning btn-sm">' . Sanitizer::escape($_LANG['cv_api_revoke']) . '</button></form> ';
    } else {
        echo '<form method="post" style="display:inline;"><input type="hidden" name="cv_token" value="' . Csrf::token() . '"><input type="hidden" name="op" value="activate"><input type="hidden" name="id" value="' . Sanitizer::escape($t->id) . '"><button type="submit" class="btn btn-success btn-sm">' . Sanitizer::escape($_LANG['cv_api_activate']) . '</button></form> ';
    }
    echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete token?\');"><input type="hidden" name="cv_token" value="' . Csrf::token() . '"><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="' . Sanitizer::escape($t->id) . '"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>';
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
