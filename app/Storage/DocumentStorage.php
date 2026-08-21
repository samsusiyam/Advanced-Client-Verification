<?php

namespace ClientVerification\Storage;

/**
 * Stores uploaded documents outside the web root with random filenames and
 * optional at-rest encryption. Mitigates path traversal and direct web access.
 */
class DocumentStorage
{
    private string $basePath;
    private bool $encrypt;
    private string $key;

    public function __construct(string $basePath = '', bool $encrypt = false, string $key = '')
    {
        if (empty($basePath)) {
            $basePath = __DIR__ . '/../../storage';
        }
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->encrypt = $encrypt;
        $this->key = $key;
    }

    /**
     * A usable encryption key must be configured; never fall back to a known default.
     */
    private function hasKey(): bool
    {
        return !empty($this->key) && strlen($this->key) >= 16;
    }

    /**
     * @return array{success:bool,error:string,stored_filename:string,storage_path:string,sha256:string}
     */
    public function store(string $tmpPath, int $verificationId, string $extension): array
    {
        $dir = $this->basePath . '/documents/' . $verificationId;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                return ['success' => false, 'error' => 'Storage directory unavailable', 'stored_filename' => '', 'storage_path' => '', 'sha256' => ''];
            }
        }

        // Random filename; original filename is never used on disk.
        $storedName = bin2hex(random_bytes(8)) . '.' . ltrim($extension, '.');
        $finalPath = $dir . '/' . $storedName;

        $content = file_get_contents($tmpPath);
        if ($content === false) {
            return ['success' => false, 'error' => 'Cannot read uploaded file', 'stored_filename' => '', 'storage_path' => '', 'sha256' => ''];
        }

        $sha256 = hash('sha256', $content);

        if ($this->encrypt) {
            if (!$this->hasKey()) {
                return ['success' => false, 'error' => 'Encryption key not configured', 'stored_filename' => '', 'storage_path' => '', 'sha256' => ''];
            }
            $iv = random_bytes(16);
            $enc = openssl_encrypt($content, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);
            if ($enc === false) {
                return ['success' => false, 'error' => 'Encryption failed', 'stored_filename' => '', 'storage_path' => '', 'sha256' => ''];
            }
            $content = $iv . $enc;
        }

        if (file_put_contents($finalPath, $content) === false) {
            return ['success' => false, 'error' => 'Cannot write file', 'stored_filename' => '', 'storage_path' => '', 'sha256' => ''];
        }

        @chmod($finalPath, 0640);

        return [
            'success' => true,
            'error' => '',
            'stored_filename' => $storedName,
            'storage_path' => $finalPath,
            'sha256' => $sha256,
        ];
    }

    /**
     * Read file contents (decrypting if necessary).
     */
    public function read(string $storagePath, bool $isEncrypted): ?string
    {
        if (!file_exists($storagePath)) {
            return null;
        }
        $realBase = realpath($this->basePath);
        $realPath = realpath($storagePath);
        if ($realPath === false || $realBase === false) {
            return null;
        }
        $normBase = rtrim(str_replace('\\', '/', strtolower($realBase)), '/') . '/';
        $normPath = str_replace('\\', '/', strtolower($realPath));
        if (strpos($normPath, $normBase) !== 0) {
            return null;
        }
        $content = file_get_contents($storagePath);
        if ($content === false) {
            return null;
        }
        if ($isEncrypted) {
            if (!$this->hasKey()) {
                return null;
            }
            $iv = substr($content, 0, 16);
            $data = substr($content, 16);
            $dec = openssl_decrypt($data, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);
            return $dec === false ? null : $dec;
        }
        return $content;
    }

    public function delete(string $storagePath): void
    {
        if (file_exists($storagePath)) {
            @unlink($storagePath);
        }
    }
}
