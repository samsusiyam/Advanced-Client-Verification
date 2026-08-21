<?php

namespace ClientVerification\Security;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Fixed-window rate limiter backed by the mod_cv_rate_limits table.
 * Defends against brute force and rate-limit bypass on verification starts.
 */
class RateLimiter
{
    /**
     * @param string $bucket e.g. 'verification_start'
     * @param string $key    e.g. client id or IP
     * @param int    $max    maximum attempts in the window
     * @param int    $window window length in seconds
     */
    public static function attempt(string $bucket, string $key, int $max, int $window): bool
    {
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - $window);

        $row = Capsule::table('mod_cv_rate_limits')
            ->where('bucket', $bucket)
            ->where('key', $key)
            ->first();

        if (!$row) {
            Capsule::table('mod_cv_rate_limits')->insert([
                'bucket' => $bucket,
                'key' => $key,
                'attempts' => 1,
                'window_start' => date('Y-m-d H:i:s', $now),
                'created_at' => date('Y-m-d H:i:s', $now),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
            return true;
        }

        // If window expired, reset.
        if (strtotime($row->window_start) < $now - $window) {
            Capsule::table('mod_cv_rate_limits')
                ->where('id', $row->id)
                ->update([
                    'attempts' => 1,
                    'window_start' => date('Y-m-d H:i:s', $now),
                    'updated_at' => date('Y-m-d H:i:s', $now),
                ]);
            return true;
        }

        if ($row->attempts >= $max) {
            return false;
        }

        Capsule::table('mod_cv_rate_limits')
            ->where('id', $row->id)
            ->increment('attempts', 1, ['updated_at' => date('Y-m-d H:i:s', $now)]);

        return true;
    }

    public static function getClientIp(): string
    {
        // REMOTE_ADDR is set by the web server and is not client-spoofable.
        // X-Forwarded-For / X-Real-IP are only trustworthy behind a known proxy,
        // so they are only used as a fallback.
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return trim($_SERVER['REMOTE_ADDR']);
        }
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $parts = explode(',', $_SERVER[$k]);
                return trim($parts[0]);
            }
        }
        return '0.0.0.0';
    }
}
