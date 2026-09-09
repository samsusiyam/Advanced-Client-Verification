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
        if (strpos($storagePath, '..') !== false) {
            return null;
        }

        $cleanPath = str_replace('\\', '/', $storagePath);
        $fileName = basename($cleanPath);
        $parentDir = basename(dirname($cleanPath));
        $moduleStorage = dirname(__DIR__, 2) . '/storage';

        $candidates = [
            $storagePath,
            $cleanPath,
            $this->basePath . '/' . ltrim($cleanPath, '/'),
            $moduleStorage . '/' . ltrim($cleanPath, '/'),
            $this->basePath . '/documents/' . $parentDir . '/' . $fileName,
            $moduleStorage . '/documents/' . $parentDir . '/' . $fileName,
            $this->basePath . '/documents/' . $fileName,
            $moduleStorage . '/documents/' . $fileName,
        ];

        $resolvedPath = null;
        foreach ($candidates as $cand) {
            if (!empty($cand) && file_exists($cand) && is_file($cand)) {
                $resolvedPath = $cand;
                break;
            }
        }

        if (!$resolvedPath) {
            return null;
        }

        $content = @file_get_contents($resolvedPath);
        if ($content === false || $content === '') {
            return null;
        }

        if ($isEncrypted) {
            // If already a valid unencrypted image or PDF (magic bytes match), return directly
            if ($this->isPlainMedia($content)) {
                return $content;
            }

            $keysToTry = [];
            if (!empty($this->key)) {
                $keysToTry[] = $this->key;
            }
            if (function_exists('cv_derive_encryption_key')) {
                $keysToTry[] = cv_derive_encryption_key();
            }

            foreach ($keysToTry as $candidateKey) {
                if (!empty($candidateKey)) {
                    $iv = substr($content, 0, 16);
                    $data = substr($content, 16);
                    $dec = @openssl_decrypt($data, 'AES-256-CBC', $candidateKey, OPENSSL_RAW_DATA, $iv);
                    if ($dec !== false && $dec !== null && strlen($dec) > 0) {
                        return $dec;
                    }
                }
            }

            // Fallback if decryption key doesn't match: return content
            return $content;
        }

        return $content;
    }

    private function isPlainMedia(string $content): bool
    {
        if (strlen($content) < 4) {
            return false;
        }
        // JPEG: FF D8 FF
        if (substr($content, 0, 3) === "\xFF\xD8\xFF") {
            return true;
        }
        // PNG: \x89PNG
        if (substr($content, 0, 4) === "\x89PNG") {
            return true;
        }
        // PDF: %PDF
        if (substr($content, 0, 4) === "%PDF") {
            return true;
        }
        // GIF: GIF8
        if (substr($content, 0, 4) === "GIF8") {
            return true;
        }
        // WEBP: RIFF....WEBP
        if (substr($content, 0, 4) === "RIFF" && substr($content, 8, 4) === "WEBP") {
            return true;
        }
        return false;
    }

    public function delete(string $storagePath): void
    {
        if (strpos($storagePath, '..') !== false) {
            return;
        }

        $cleanPath = str_replace('\\', '/', $storagePath);
        $fileName = basename($cleanPath);
        $parentDir = basename(dirname($cleanPath));
        $moduleStorage = dirname(__DIR__, 2) . '/storage';

        $candidates = [
            $storagePath,
            $cleanPath,
            $this->basePath . '/' . ltrim($cleanPath, '/'),
            $moduleStorage . '/' . ltrim($cleanPath, '/'),
            $this->basePath . '/documents/' . $parentDir . '/' . $fileName,
            $moduleStorage . '/documents/' . $parentDir . '/' . $fileName,
        ];

        foreach ($candidates as $cand) {
            if (!empty($cand) && file_exists($cand) && is_file($cand)) {
                @unlink($cand);
            }
        }
    }
}
