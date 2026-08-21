<?php

namespace ClientVerification\Security;

class Sanitizer
{
    /**
     * Escape output for safe HTML display (mitigates XSS).
     */
    public static function escape($value): string
    {
        if (is_array($value)) {
            return htmlspecialchars(json_encode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitize a value for use inside an HTTP header (mitigates CRLF injection).
     * Strips all CR/LF and other control characters.
     */
    public static function headerValue($value): string
    {
        return preg_replace('/[\r\n\t]/', '', (string) $value);
    }

    /**
     * Sanitize a string for storage (strip tags, trim).
     */
    public static function cleanString($value, int $maxLength = 255): string
    {
        $value = strip_tags((string) $value);
        $value = trim($value);
        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Sanitize general single-line text (strip tags, control characters, trim).
     */
    public static function text($value, int $maxLength = 255): string
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value);
        $value = trim($value);
        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Sanitize alphanumeric with underscores and dashes (e.g. document type keys, slugs).
     */
    public static function alphanumeric($value): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value);
    }

    /**
     * Sanitize an integer.
     */
    public static function int($value): int
    {
        return (int) preg_replace('/[^0-9-]/', '', (string) $value);
    }

    /**
     * Allowlist filter for mass-assignment. Only returns keys present in $allowed.
     */
    public static function only(array $input, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }
        return $out;
    }

    /**
     * Escape a value for CSV export (mitigates CSV injection).
     */
    public static function csvCell($value): string
    {
        $value = (string) $value;
        if (in_array(substr($value, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'" . $value;
        }
        return $value;
    }
}
