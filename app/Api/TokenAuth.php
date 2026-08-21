<?php

namespace ClientVerification\Api;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * API token authentication: hashed, scoped, revocable, expirable, rate limited.
 */
class TokenAuth
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Validate a raw bearer token. Returns the token row or null.
     */
    public static function authenticate(?string $bearer): ?object
    {
        if (!$bearer) {
            return null;
        }
        $hash = self::hash($bearer);
        $row = Capsule::table('mod_cv_api_tokens')->where('token_hash', $hash)->first();
        if (!$row) {
            return null;
        }
        if (!$row->active) {
            return null;
        }
        if ($row->expires_at && strtotime($row->expires_at) < time()) {
            return null;
        }
        return $row;
    }

    public static function checkScope(object $token, string $scope): bool
    {
        $scopes = json_decode($token->scopes, true);
        if (!is_array($scopes)) {
            return false;
        }
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public static function rateLimited(object $token): bool
    {
        $now = time();
        $window = 60;
        $windowStart = date('Y-m-d H:i:s', $now - $window);
        $count = Capsule::table('mod_cv_audit_logs')
            ->where('admin_id', -1)
            ->where('note', 'api:' . $token->id)
            ->where('created_at', '>=', $windowStart)
            ->count();
        if ($count >= $token->rate_limit) {
            return true;
        }
        Capsule::table('mod_cv_audit_logs')->insert([
            'verification_id' => 0,
            'admin_id' => -1,
            'action' => 'api_request',
            'note' => 'api:' . $token->id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('mod_cv_api_tokens')->where('id', $token->id)
            ->update(['request_count' => $token->request_count + 1, 'last_used_at' => date('Y-m-d H:i:s')]);
        return false;
    }
}
