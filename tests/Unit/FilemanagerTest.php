<?php
namespace GuillermoMartinez\Filemanager\Tests\Unit;

use GuillermoMartinez\Filemanager\Filemanager;
use PHPUnit\Framework\TestCase;

class FilemanagerTest extends TestCase
{
    private Filemanager $filemanager;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = TEST_TEMP_DIR . '/filemanager-test';
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }

        $config = [
            'doc_root' => $this->testDir,
            'source' => '',
            'url' => 'http://localhost/',
            'debug' => false,
            'ext' => ['jpg', 'jpeg', 'gif', 'png', 'txt', 'pdf'],
            'images' => [
                'images_ext' => ['jpg', 'jpeg', 'gif', 'png'],
                'resize' => [
                    'small' => [120, 90, true, true],
                ],
                'quality' => 90,
            ],
            'upload' => [
                'overwrite' => false,
                'size_max' => 10,
            ],
            'folder_excludes' => [],
            'folder_thumb' => '_thumbs',
            'debugfile' => TEST_TEMP_DIR . '/filemanager.log',
            'storage' => [
                'root' => $this->testDir,
            ],
        ];

        $this->filemanager = new Filemanager($config);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testFilemanagerInitialization(): void
    {
        $this->assertInstanceOf(Filemanager::class, $this->filemanager);
    }

    public function testValidPathValidation(): void
    {
        $this->assertTrue($this->filemanager->validPath('/'));
        $this->assertTrue($this->filemanager->validPath('/folder/'));
        $this->assertTrue($this->filemanager->validPath('/folder/subfolder/'));
        $this->assertTrue($this->filemanager->validPath('/file.txt'));

        $this->assertFalse($this->filemanager->validPath(''));
        $this->assertFalse($this->filemanager->validPath('no-leading-slash'));
        $this->assertFalse($this->filemanager->validPath('/../etc/passwd'));
    }

    public function testValidNameFile(): void
    {
        $this->assertTrue($this->filemanager->validNameFile('document.txt', false));
        $this->assertTrue($this->filemanager->validNameFile('my-file_2024.txt', false));
        $this->assertTrue($this->filemanager->validNameFile('file with spaces.txt', false));

        $this->assertFalse($this->filemanager->validNameFile('..', false));
        $this->assertFalse($this->filemanager->validNameFile('.', false));
        $this->assertFalse($this->filemanager->validNameFile('file/path.txt', false));
    }

    public function testValidExtension(): void
    {
        $this->assertTrue($this->filemanager->validExt('document.pdf'));
        $this->assertTrue($this->filemanager->validExt('image.jpg'));
        $this->assertTrue($this->filemanager->validExt('image.png'));

        $this->assertFalse($this->filemanager->validExt('executable.exe'));
        $this->assertFalse($this->filemanager->validExt('script.php'));
        $this->assertFalse($this->filemanager->validExt('shell.sh'));
    }

    public function testGetExtension(): void
    {
        $this->assertEquals('txt', $this->filemanager->getExtension('file.txt'));
        $this->assertEquals('pdf', $this->filemanager->getExtension('document.pdf'));
        $this->assertEquals('jpg', $this->filemanager->getExtension('image.jpg'));
    }

    public function testClearNameFile(): void
    {
        $cleaned = $this->filemanager->clearNameFile('My File (2024).txt');
        $this->assertStringNotContainsString('(', $cleaned);
        $this->assertStringNotContainsString(')', $cleaned);

        $cleaned = $this->filemanager->clearNameFile('Café Document.txt');
        $this->assertStringNotContainsString('é', $cleaned);

        $cleaned = $this->filemanager->clearNameFile('Multiple   Spaces.txt');
        $this->assertStringNotContainsString('   ', $cleaned);
    }

    public function testRemoveExtension(): void
    {
        $this->assertEquals('file', $this->filemanager->removeExtension('file.txt'));
        $this->assertEquals('my-document', $this->filemanager->removeExtension('my-document.pdf'));
        $this->assertEquals('archive', $this->filemanager->removeExtension('archive.tar.gz'));
    }

    public function testGetFullPath(): void
    {
        $fullPath = $this->filemanager->getFullPath();
        $this->assertStringContainsString($this->testDir, $fullPath);
    }

    public function testGetAllFilesReturnsArray(): void
    {
        file_put_contents($this->testDir . '/test1.txt', 'content1');
        file_put_contents($this->testDir . '/test2.txt', 'content2');

        $files = $this->filemanager->getAllFiles('/');

        $this->assertIsArray($files);
        $this->assertGreaterThanOrEqual(2, count($files));
    }

    public function testSanitizeHtmlAndPathTraversal(): void
    {
        $sanitized = $this->filemanager->sanitize('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<', $sanitized);
        $this->assertStringNotContainsString('>', $sanitized);

        $sanitized = $this->filemanager->sanitize('file/../../../etc/passwd');
        $this->assertStringNotContainsString('../', $sanitized);
    }

    public function testNewFolder(): void
    {
        $result = $this->filemanager->newFolder('new-test-folder', '/');
        $this->assertTrue($result);
        $this->assertTrue(is_dir($this->testDir . '/new-test-folder'));
    }

    public function testDelete(): void
    {
        file_put_contents($this->testDir . '/delete-me.txt', 'delete');

        $this->filemanager->delete('delete-me.txt', '/');

        $this->assertFalse(file_exists($this->testDir . '/delete-me.txt'));
    }

    public function testRename(): void
    {
        file_put_contents($this->testDir . '/oldname.txt', 'content');

        $this->filemanager->rename('oldname.txt', 'newname.txt', '/');

        $this->assertFalse(file_exists($this->testDir . '/oldname.txt'));
        $this->assertTrue(file_exists($this->testDir . '/newname.txt'));
    }
}
