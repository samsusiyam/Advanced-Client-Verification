<?php

namespace ClientVerification\Helpers;

/**
 * Minimal cURL-based HTTP client. Uses only PHP core + cURL (no external deps),
 * compatible with shared hosting. No shell_exec / exec used anywhere.
 */
class Http
{
    /**
     * Perform a POST request with JSON body.
     */
    public static function post(string $url, array $payload, array $headers = [], int $timeout = 30): array
    {
        $body = json_encode($payload);
        $defaultHeaders = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];
        $allHeaders = array_merge($defaultHeaders, $headers);

        return self::request($url, 'POST', $body, $allHeaders, $timeout);
    }

    public static function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return self::request($url, 'GET', '', $headers, $timeout);
    }

    private static function request(string $url, string $method, string $body, array $headers, int $timeout): array
    {
        // SSRF protection: only allow http(s) schemes.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            return ['success' => false, 'error' => 'Invalid URL scheme', 'data' => null, 'http_code' => 0];
        }

        // SSRF protection: never reach internal/loopback/link-local hosts, even via redirects.
        if (self::isBlockedHost($url)) {
            return ['success' => false, 'error' => 'Blocked destination host', 'data' => null, 'http_code' => 0];
        }

        $maxRedirs = 3;
        $redirs = 0;

        while (true) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            if ($method === 'POST' && $body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                return ['success' => false, 'error' => $error ?: 'Request failed', 'data' => null, 'http_code' => $httpCode];
            }

            // Manual redirect handling with per-hop host validation (prevents redirect-based SSRF).
            if (in_array($httpCode, [301, 302, 303, 307, 308], true) && $redirs < $maxRedirs) {
                $location = self::resolveRedirect($url, $response);
                if ($location === null || self::isBlockedHost($location)) {
                    return ['success' => false, 'error' => 'Blocked redirect target', 'data' => null, 'http_code' => $httpCode];
                }
                $url = $location;
                if ($httpCode === 303) {
                    $method = 'GET';
                    $body = '';
                }
                $redirs++;
                continue;
            }

            break;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = ['raw' => $response];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        return ['success' => $success, 'error' => $success ? '' : ($error ?: 'HTTP ' . $httpCode), 'data' => $data, 'http_code' => $httpCode];
    }

    /**
     * Resolve a redirect Location header, supporting absolute and relative URLs.
     */
    private static function resolveRedirect(string $base, string $response): ?string
    {
        if (!preg_match('/^location:\s*(.+)$/mi', $response, $m)) {
            return null;
        }
        $loc = trim($m[1]);
        if (preg_match('/^https?:\/\//i', $loc)) {
            return $loc;
        }
        // Relative redirect: build absolute URL from the request base.
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $loc[0] === '/' ? $loc : rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . ltrim($loc, '/');
        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Returns true if the URL host resolves to a private/loopback/link-local address.
     */
    private static function isBlockedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return true;
        }

        // Whitelist known legitimate external verification provider domains
        if (preg_match('/(^|\.)(didit\.me|sumsub\.com|veriff\.com|withpersona\.com)$/i', $host)) {
            return false;
        }

        // Literal IP addresses
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host);
        }

        // Block known local hostnames directly
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }

        // Block internal private IP patterns in domain
        if (preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|169\.254\.|0\.0\.0\.0)/i', $host)) {
            return true;
        }

        // Resolve hostname if possible and reject any private result
        $ips = @gethostbynamel($host);
        if (is_array($ips) && !empty($ips)) {
            foreach ($ips as $ip) {
                if (self::isPrivateIp($ip)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return true;
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return !filter_var($ip, FILTER_VALIDATE_IP, $flags);
    }
}
