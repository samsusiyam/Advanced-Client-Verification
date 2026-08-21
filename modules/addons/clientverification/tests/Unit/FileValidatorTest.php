<?php

namespace ClientVerification\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClientVerification\Validation\FileValidator;

class FileValidatorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cv_test_' . uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*'));
        @rmdir($this->tmp);
    }

    public function testValidPdfPasses()
    {
        $path = $this->tmp . '/doc.pdf';
        file_put_contents($path, "%PDF-1.4\n" . str_repeat('x', 100));
        $v = new FileValidator(['pdf', 'jpg', 'jpeg', 'png', 'webp'], 1024 * 1024);
        $r = $v->validate($path, 'doc.pdf');
        $this->assertTrue($r['success'], $r['error'] ?? '');
    }

    public function testPhpFileRejected()
    {
        $path = $this->tmp . '/evil.php';
        file_put_contents($path, "<?php echo 'x';");
        $v = new FileValidator(['pdf', 'jpg', 'jpeg', 'png', 'webp'], 1024 * 1024);
        $r = $v->validate($path, 'evil.php');
        $this->assertFalse($r['success']);
    }

    public function testDoubleExtensionRejected()
    {
        $path = $this->tmp . '/evil.php.jpg';
        // Craft a JPEG signature but name indicates double extension; validator
        // rejects blocked segment in filename regardless of content.
        file_put_contents($path, "\xFF\xD8\xFF\xE0" . str_repeat('x', 50));
        $v = new FileValidator(['pdf', 'jpg', 'jpeg', 'png', 'webp'], 1024 * 1024);
        $r = $v->validate($path, 'evil.php.jpg');
        $this->assertFalse($r['success']);
    }

    public function testMimeMismatchRejected()
    {
        $path = $this->tmp . '/fake.jpg';
        file_put_contents($path, "%PDF-1.4 fake pdf with jpg name");
        $v = new FileValidator(['pdf', 'jpg', 'jpeg', 'png', 'webp'], 1024 * 1024);
        $r = $v->validate($path, 'fake.jpg');
        $this->assertFalse($r['success']);
    }

    public function testOversizedRejected()
    {
        $path = $this->tmp . '/big.pdf';
        file_put_contents($path, "%PDF-1.4\n" . str_repeat('x', 200));
        $v = new FileValidator(['pdf', 'jpg', 'jpeg', 'png', 'webp'], 50);
        $r = $v->validate($path, 'big.pdf');
        $this->assertFalse($r['success']);
    }
}
