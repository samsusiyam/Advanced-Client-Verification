<?php

namespace ClientVerification\License;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * LicenseManager
 *
 * Integrates HostNibo's External License Management System (ELMS)
 * with the Advanced Client Verification module.
 *
 * @package ClientVerification\License
 * @author  HostNibo
 */
class LicenseManager
{
    public const DEFAULT_SERVER_URL   = 'https://lic.hostnibo.com';
    public const DEFAULT_PRODUCT_KEY  = 'ADVANCED-CLIENT-VERIFICATION';
    public const DEFAULT_API_KEY      = 'elms_pk_2b7c1f9a4d6e8032a1c5b9e7f3d20481';
    public const DEFAULT_API_SECRET   = 'elms_sk_9f83a1c04e7b2d6598301fbca5e47d29b8460af1c2d3e5f7';
    public const CACHE_TTL_SECONDS    = 43200; // 12 hours

    private static ?self $instance = null;

    private string $serverUrl;
    private string $productKey;
    private string $apiKey;
    private string $apiSecret;
    private string $cacheDir;

    private ?string $lastResponseSig = null;
    private ?int $lastResponseTs = null;

    public function __construct()
    {
        $this->serverUrl  = $this->resolveServerUrl();
        $this->productKey = $this->resolveProductKey();
        $this->apiKey     = $this->resolveApiKey();
        $this->apiSecret  = $this->resolveApiSecret();
        $this->cacheDir   = dirname(__DIR__, 2) . '/storage/license';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fast check: returns true if the module currently has an active valid license.
     */
    public static function isLicensed(): bool
    {
        return self::getInstance()->checkLicenseValid();
    }

    /**
     * Resolve Server URL: supports custom DB setting, constant, local environment auto-detection, and production default.
     */
    public function resolveServerUrl(): string
    {
        // 1. Explicit PHP constant (e.g. for development / testing)
        if (defined('CV_LICENSE_SERVER') && !empty(constant('CV_LICENSE_SERVER'))) {
            return rtrim(constant('CV_LICENSE_SERVER'), '/');
        }

        // 2. Setting from mod_cv_settings or tbladdonmodules
        $url = '';
        if (function_exists('cv_setting')) {
            $url = (string) cv_setting('license_server_url', '');
        }
        if (empty($url)) {
            try {
                $url = (string) Capsule::table('tbladdonmodules')
                    ->where('module', 'clientverification')
                    ->where('setting', 'license_server_url')
                    ->value('value');
            } catch (\Throwable $e) {}
        }
        if (!empty($url)) {
            return rtrim($url, '/');
        }

        // 3. Auto-detect local development environment on localhost / 127.0.0.1
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')) {
            if (is_dir('C:/xampp/htdocs/license') || is_dir(dirname(__DIR__, 4) . '/license')) {
                return 'http://localhost/license/public';
            }
        }

        // 4. Default production license server
        return self::DEFAULT_SERVER_URL;
    }

    /**
     * Resolve Product Key from settings or default.
     */
    public function resolveProductKey(): string
    {
        $pk = '';
        if (function_exists('cv_setting')) {
            $pk = (string) cv_setting('license_product_key', '');
        }
        return !empty($pk) ? trim($pk) : self::DEFAULT_PRODUCT_KEY;
    }

    /**
     * Resolve API Public Key.
     */
    public function resolveApiKey(): string
    {
        $key = '';
        if (function_exists('cv_setting')) {
            $key = (string) cv_setting('license_api_key', '');
        }
        return !empty($key) ? trim($key) : self::DEFAULT_API_KEY;
    }

    /**
     * Resolve API Secret Key.
     */
    public function resolveApiSecret(): string
    {
        $secret = '';
        if (function_exists('cv_setting')) {
            $secret = (string) cv_setting('license_api_secret', '');
        }
        return !empty($secret) ? trim($secret) : self::DEFAULT_API_SECRET;
    }

    /**
     * Retrieve the stored license key.
     */
    public function getLicenseKey(): string
    {
        $key = '';
        if (function_exists('cv_setting')) {
            $key = (string) cv_setting('license_key', '');
        }
        if (empty($key)) {
            try {
                $key = (string) Capsule::table('tbladdonmodules')
                    ->where('module', 'clientverification')
                    ->where('setting', 'license_key')
                    ->value('value');
            } catch (\Throwable $e) {}
        }
        return trim($key);
    }

    /**
     * Save the license key to mod_cv_settings and tbladdonmodules.
     */
    public function saveLicenseKey(string $key): void
    {
        $key = trim($key);
        if (function_exists('cv_setting_set')) {
            cv_setting_set('license_key', $key);
        }
        try {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => 'clientverification', 'setting' => 'license_key'],
                ['value' => $key]
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Detect the server domain for verification.
     */
    public function getDomain(): string
    {
        $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        if (empty($domain) && function_exists('cv_system_url')) {
            $parsed = parse_url(cv_system_url(), PHP_URL_HOST);
            if (!empty($parsed)) {
                $domain = $parsed;
            }
        }
        $domain = preg_replace('/:\d+$/', '', (string)$domain);
        return strtolower(trim($domain)) ?: 'localhost';
    }

    /**
     * Detect the server IP address for verification.
     */
    public function getIp(): string
    {
        $ip = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '');
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            $ip = gethostbyname(gethostname()) ?: '127.0.0.1';
        }
        return trim($ip);
    }

    /**
     * Determine if license is currently valid (cached or live).
     */
    public function checkLicenseValid(): bool
    {
        $key = $this->getLicenseKey();
        if (empty($key)) {
            return false;
        }

        // 1. Check verified cached state
        $cached = $this->readCache($key, $this->getDomain());
        if ($cached !== null && !empty($cached['status'])) {
            return true;
        }

        // 2. Check if cached status in settings is active and verified within grace period
        $status = cv_setting('license_status', '');
        $lastCheck = (int) cv_setting('license_last_check', 0);
        if ($status === 'active' && (time() - $lastCheck) < self::CACHE_TTL_SECONDS) {
            return true;
        }

        // 3. Fallback: run a live check
        $res = $this->verify(false);
        return !empty($res['status']);
    }

    /**
     * Verify license with ELMS server.
     *
     * @param bool $forceRemote Force remote HTTP call regardless of cache
     * @return array{status:bool,message:string,data:array<string,mixed>,is_cached?:bool}
     */
    public function verify(bool $forceRemote = false): array
    {
        $key    = $this->getLicenseKey();
        $domain = $this->getDomain();
        $ip     = $this->getIp();

        if (empty($key)) {
            return [
                'status'  => false,
                'message' => 'No license key entered. Please activate your license to enable the module.',
                'data'    => [],
            ];
        }

        // Check cache first if not forced
        if (!$forceRemote) {
            $cached = $this->readCache($key, $domain);
            if ($cached !== null) {
                $cached['is_cached'] = true;
                $cached['message'] = ($cached['message'] ?? 'License Valid') . ' (cached)';
                return $cached;
            }
        }

        $payload = [
            'license_key' => $key,
            'domain'      => $domain,
            'ip'          => $ip,
            'product'     => $this->productKey,
        ];

        try {
            $res = $this->post('/api/license/verify', $payload);

            if (!empty($res['status'])) {
                cv_setting_set('license_status', 'active');
                cv_setting_set('license_last_check', (string) time());
                cv_setting_set('license_expiry', (string) ($res['data']['expiry'] ?? ''));
                cv_setting_set('license_message', (string) ($res['message'] ?? 'License Valid'));

                if (!empty($this->lastResponseSig) && !empty($this->lastResponseTs)) {
                    $this->writeCache($key, $domain, $res, $this->lastResponseSig, $this->lastResponseTs);
                }
            } else {
                $status = 'invalid';
                if (stripos($res['message'] ?? '', 'expired') !== false) {
                    $status = 'expired';
                } elseif (stripos($res['message'] ?? '', 'suspended') !== false) {
                    $status = 'suspended';
                } elseif (stripos($res['message'] ?? '', 'terminated') !== false) {
                    $status = 'terminated';
                }
                cv_setting_set('license_status', $status);
                cv_setting_set('license_message', (string) ($res['message'] ?? 'License invalid'));
                $this->clearCache($key, $domain);
            }

            return $res;
        } catch (\Throwable $e) {
            // In case of network outage, fall back to cache if available
            $cached = $this->readCache($key, $domain);
            if ($cached !== null) {
                $cached['is_cached'] = true;
                $cached['message'] = ($cached['message'] ?? 'License Valid') . ' (offline cached)';
                return $cached;
            }

            return [
                'status'  => false,
                'message' => 'License server connection failed: ' . $e->getMessage(),
                'data'    => [],
            ];
        }
    }

    /**
     * Activate the license for this install.
     *
     * @param string|null $newKey Optional license key to save and activate
     * @return array{status:bool,message:string,data:array<string,mixed>}
     */
    public function activate(?string $newKey = null): array
    {
        if ($newKey !== null && trim($newKey) !== '') {
            $this->saveLicenseKey(trim($newKey));
        }

        $key = $this->getLicenseKey();
        if (empty($key)) {
            return [
                'status'  => false,
                'message' => 'Please provide a valid license key.',
                'data'    => [],
            ];
        }

        $domain = $this->getDomain();
        $ip     = $this->getIp();

        $payload = [
            'license_key'     => $key,
            'domain'          => $domain,
            'ip'              => $ip,
            'product'         => $this->productKey,
            'server_hostname' => gethostname() ?: 'unknown',
            'install_path'    => dirname(__DIR__, 2),
        ];

        try {
            $res = $this->post('/api/license/activate', $payload);

            if (!empty($res['status']) || ($res['message'] ?? '') === 'Already activated') {
                cv_setting_set('license_status', 'active');
                cv_setting_set('license_last_check', (string) time());
                cv_setting_set('license_expiry', (string) ($res['data']['expiry'] ?? ''));
                cv_setting_set('license_message', (string) ($res['message'] ?? 'License Activated'));

                if (!empty($this->lastResponseSig) && !empty($this->lastResponseTs)) {
                    $this->writeCache($key, $domain, $res, $this->lastResponseSig, $this->lastResponseTs);
                }

                if (function_exists('cv_log_audit')) {
                    cv_log_audit(0, 'license_activated', (int)($_SESSION['adminid'] ?? 0), 'Module license activated successfully for domain ' . $domain);
                }

                return [
                    'status'  => true,
                    'message' => 'License activated successfully! Full module features are now unlocked.',
                    'data'    => $res['data'] ?? [],
                ];
            }

            cv_setting_set('license_status', 'invalid');
            cv_setting_set('license_message', (string) ($res['message'] ?? 'Activation failed'));
            return $res;
        } catch (\Throwable $e) {
            return [
                'status'  => false,
                'message' => 'Activation failed: ' . $e->getMessage(),
                'data'    => [],
            ];
        }
    }

    /**
     * Deactivate this install (freeing up the activation slot on ELMS).
     *
     * @return array{status:bool,message:string,data:array<string,mixed>}
     */
    public function deactivate(): array
    {
        $key    = $this->getLicenseKey();
        $domain = $this->getDomain();

        if (empty($key)) {
            return ['status' => false, 'message' => 'No active license key to deactivate.', 'data' => []];
        }

        try {
            $res = $this->post('/api/license/deactivate', [
                'license_key' => $key,
                'domain'      => $domain,
            ]);
        } catch (\Throwable $e) {
            $res = ['status' => false, 'message' => $e->getMessage(), 'data' => []];
        }

        $this->clearCache($key, $domain);
        cv_setting_set('license_status', 'deactivated');
        cv_setting_set('license_message', 'License deactivated by administrator.');

        if (function_exists('cv_log_audit')) {
            cv_log_audit(0, 'license_deactivated', (int)($_SESSION['adminid'] ?? 0), 'Module license deactivated for domain ' . $domain);
        }

        return [
            'status'  => true,
            'message' => 'License deactivated successfully. The activation slot has been freed on the license server.',
            'data'    => $res['data'] ?? [],
        ];
    }

    /**
     * Check for module software updates.
     *
     * @param string $currentVersion
     * @return array{status:bool,message:string,data:array<string,mixed>}
     */
    public function checkUpdate(string $currentVersion = '1.0.0'): array
    {
        $key = $this->getLicenseKey();
        try {
            return $this->post('/api/updates/check', [
                'product'         => $this->productKey,
                'current_version' => $currentVersion,
                'license_key'     => $key,
            ]);
        } catch (\Throwable $e) {
            return [
                'status'  => false,
                'message' => 'Update check failed: ' . $e->getMessage(),
                'data'    => [],
            ];
        }
    }

    /**
     * Returns license details for the admin panel.
     *
     * @return array<string,mixed>
     */
    public function getDetails(): array
    {
        $key       = $this->getLicenseKey();
        $status    = cv_setting('license_status', 'unlicensed');
        $expiry    = cv_setting('license_expiry', '');
        $lastCheck = (int) cv_setting('license_last_check', 0);
        $message   = cv_setting('license_message', '');
        $domain    = $this->getDomain();
        $ip        = $this->getIp();

        $maskedKey = '';
        if (!empty($key)) {
            $parts = explode('-', $key);
            if (count($parts) >= 4) {
                $maskedKey = $parts[0] . '-****-****-' . end($parts);
            } else {
                $maskedKey = substr($key, 0, 4) . '****' . substr($key, -4);
            }
        }

        return [
            'license_key' => $key,
            'masked_key'  => $maskedKey,
            'status'      => $status ?: 'unlicensed',
            'is_licensed' => ($status === 'active'),
            'expiry_date' => $expiry ?: 'Lifetime / Ongoing',
            'domain'      => $domain,
            'ip'          => $ip,
            'last_check'  => $lastCheck > 0 ? date('Y-m-d H:i:s', $lastCheck) : 'Never',
            'message'     => $message,
            'server_url'  => $this->serverUrl,
        ];
    }

    // -------------------------------------------------------------------------
    // Cryptographic Signed HTTP Transport
    // -------------------------------------------------------------------------

    /**
     * Send HMAC-SHA256 signed POST request to the ELMS license server.
     *
     * @param string $path Endpoint path (e.g. '/api/license/verify')
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts   = time();
        $sig  = hash_hmac('sha256', $ts . '.' . $this->apiKey . '.' . hash('sha256', $body), $this->apiSecret);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Api-Key: ' . $this->apiKey,
            'X-Timestamp: ' . $ts,
            'X-Signature: ' . $sig,
        ];

        $respHeaders = [];
        $url = $this->serverUrl . $path;
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                    $raw = $line;
                    $line = trim($line);
                    if ($line !== '' && !str_starts_with($line, 'HTTP/')) {
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) {
                            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                        }
                    }
                    return strlen($raw);
                },
            ]);

            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                throw new \RuntimeException('cURL error connecting to ' . $url . ': ' . $err);
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => 15,
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                throw new \RuntimeException('HTTP stream failed connecting to ' . $url);
            }
            if (isset($http_response_header)) {
                foreach ($http_response_header as $line) {
                    $line = trim($line);
                    if ($line !== '' && !str_starts_with($line, 'HTTP/')) {
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) {
                            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                        }
                    }
                }
            }
        }

        // Store response headers for cache verification
        $this->lastResponseSig = $respHeaders['x-signature'] ?? null;
        $this->lastResponseTs  = isset($respHeaders['x-timestamp']) ? (int) $respHeaders['x-timestamp'] : null;

        // Verify response signature if present
        if ($this->lastResponseSig !== null && $this->lastResponseTs !== null) {
            $expectedSig = hash_hmac('sha256', $this->lastResponseTs . '.' . $this->apiKey . '.' . hash('sha256', (string)$resp), $this->apiSecret);
            if (!hash_equals($expectedSig, $this->lastResponseSig)) {
                throw new \RuntimeException('Untrusted server response: Bad HMAC signature');
            }
        }

        $decoded = json_decode((string)$resp, true);
        if (!is_array($decoded)) {
            $cleanSnippet = substr(trim(strip_tags((string)$resp)), 0, 150);
            throw new \RuntimeException("License server at {$url} returned HTTP {$httpCode} (Non-JSON): " . ($cleanSnippet ?: 'Empty response body'));
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Offline Cache Storage
    // -------------------------------------------------------------------------

    private function getCacheFile(string $licenseKey, string $domain): string
    {
        $hash = hash('sha256', $licenseKey . '|' . $this->productKey . '|' . $domain);
        return $this->cacheDir . '/lic_' . substr($hash, 0, 32) . '.json';
    }

    private function writeCache(string $licenseKey, string $domain, array $payload, string $sig, int $ts): void
    {
        $file = $this->getCacheFile($licenseKey, $domain);
        $data = [
            'ts'      => $ts,
            'sig'     => $sig,
            'payload' => $payload,
        ];
        @file_put_contents($file, json_encode($data));
    }

    private function readCache(string $licenseKey, string $domain): ?array
    {
        $file = $this->getCacheFile($licenseKey, $domain);
        if (!is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if (!$content) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['payload'], $data['sig'], $data['ts'])) {
            return null;
        }

        // Check expiration
        if ((time() - (int)$data['ts']) > self::CACHE_TTL_SECONDS) {
            return null;
        }

        // Verify HMAC
        $body = json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedSig = hash_hmac('sha256', $data['ts'] . '.' . $this->apiKey . '.' . hash('sha256', $body), $this->apiSecret);
        if (!hash_equals($expectedSig, (string)$data['sig'])) {
            @unlink($file);
            return null;
        }

        return $data['payload'];
    }

    private function clearCache(string $licenseKey, string $domain): void
    {
        $file = $this->getCacheFile($licenseKey, $domain);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
