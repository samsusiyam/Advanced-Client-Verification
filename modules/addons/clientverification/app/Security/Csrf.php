<?php

namespace ClientVerification\Security;

/**
 * CSRF protection using WHMCS session-bound tokens.
 */
class Csrf
{
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (empty($_SESSION['cv_csrf_token'])) {
            $_SESSION['cv_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['cv_csrf_token'];
    }

    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="cv_token" value="' . self::escape($token) . '">';
    }

    public static function check(?string $token): bool
    {
        if (empty($_SESSION['cv_csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['cv_csrf_token'], $token);
    }

    private static function escape($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
