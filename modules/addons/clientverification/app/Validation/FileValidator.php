<?php

namespace ClientVerification\Validation;

/**
 * Secure file upload validator. Defends against:
 * - Disallowed extensions (.php, .phar, etc.)
 * - Double-extension bypass (test.php.jpg)
 * - MIME / content-type mismatch
 * - Invalid file signatures (magic bytes)
 * - Oversized uploads
 * - Crafted images
 */
class FileValidator
{
    private array $allowedExtensions;
    private array $blockedExtensions = ['php', 'phtml', 'php5', 'phar', 'exe', 'sh', 'js', 'html', 'htm', 'svg', 'phps', 'php3', 'php4'];
    private int $maxBytes;
    private array $allowedMimes;

    public function __construct(array $allowedExtensions, int $maxBytes)
    {
        $this->allowedExtensions = array_map('strtolower', $allowedExtensions);
        $this->maxBytes = $maxBytes;
        $this->allowedMimes = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ];
    }

    /**
     * @return array{success:bool,error:string,mime:string,extension:string}
     */
    public function validate(string $tmpPath, string $originalName): array
    {
        if (!is_uploaded_file($tmpPath) && !file_exists($tmpPath)) {
            return ['success' => false, 'error' => 'File not found', 'mime' => '', 'extension' => ''];
        }

        $size = filesize($tmpPath);
        if ($size === false || $size > $this->maxBytes) {
            return ['success' => false, 'error' => 'File exceeds maximum allowed size', 'mime' => '', 'extension' => ''];
        }
        if ($size === 0) {
            return ['success' => false, 'error' => 'Empty file', 'mime' => '', 'extension' => ''];
        }

        // Reject double-extension / polyglot names.
        $base = strtolower(basename($originalName));
        $segments = explode('.', $base);
        if (count($segments) < 2) {
            return ['success' => false, 'error' => 'Invalid filename', 'mime' => '', 'extension' => ''];
        }
        array_shift($segments); // remove name
        foreach ($segments as $seg) {
            if (in_array($seg, $this->blockedExtensions, true)) {
                return ['success' => false, 'error' => 'Blocked file type: ' . $seg, 'mime' => '', 'extension' => ''];
            }
        }

        $extension = strtolower(end($segments));
        if (!in_array($extension, $this->allowedExtensions, true)) {
            return ['success' => false, 'error' => 'Unsupported file type', 'mime' => '', 'extension' => $extension];
        }

        // MIME detection via finfo, and compare to allowed list for extension.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $allowedForExt = $this->allowedMimes[$extension] ?? [];
        if (!in_array($mime, $allowedForExt, true)) {
            return ['success' => false, 'error' => 'File content does not match its extension (MIME mismatch)', 'mime' => $mime, 'extension' => $extension];
        }

        // Validate file signature (magic bytes).
        if (!$this->validSignature($tmpPath, $extension)) {
            return ['success' => false, 'error' => 'Invalid file signature', 'mime' => $mime, 'extension' => $extension];
        }

        // For images, ensure it is a real decodable image.
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $img = @getimagesize($tmpPath);
            if ($img === false) {
                return ['success' => false, 'error' => 'Corrupt or invalid image', 'mime' => $mime, 'extension' => $extension];
            }
        }

        return ['success' => true, 'error' => '', 'mime' => $mime, 'extension' => $extension];
    }

    private function validSignature(string $path, string $ext): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $bytes = fread($handle, 12);
        fclose($handle);

        switch ($ext) {
            case 'pdf':
                return strpos($bytes, '%PDF') === 0;
            case 'jpg':
            case 'jpeg':
                return substr($bytes, 0, 3) === "\xFF\xD8\xFF";
            case 'png':
                return substr($bytes, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";
            case 'webp':
                return substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP';
            default:
                return false;
        }
    }
}
