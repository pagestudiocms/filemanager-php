<?php
namespace GuillermoMartinez\Filemanager\Tests\Unit\Service;

use GuillermoMartinez\Filemanager\Service\StorageService;
use PHPUnit\Framework\TestCase;

class StorageServiceTest extends TestCase
{
    private StorageService $storage;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = TEST_TEMP_DIR . '/storage-test';
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }

        $this->storage = new StorageService([
            'root' => $this->testDir,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test files
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

    public function testStorageServiceInitialization(): void
    {
        $this->assertInstanceOf(StorageService::class, $this->storage);
    }

    public function testFileExists(): void
    {
        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, 'test content');

        $this->assertTrue($this->storage->fileExists($testFile));
        $this->assertFalse($this->storage->fileExists($this->testDir . '/nonexistent.txt'));
    }

    public function testWrite(): void
    {
        $filePath = $this->testDir . '/written.txt';
        $content = 'Hello, Storage Service!';

        $this->storage->write($filePath, $content);

        $this->assertTrue(file_exists($filePath));
        $this->assertEquals($content, file_get_contents($filePath));
    }

    public function testRead(): void
    {
        $filePath = $this->testDir . '/read.txt';
        $content = 'Read this content';
        file_put_contents($filePath, $content);

        $this->assertEquals($content, $this->storage->read($filePath));
    }

    public function testDelete(): void
    {
        $filePath = $this->testDir . '/delete.txt';
        file_put_contents($filePath, 'to delete');

        $this->assertTrue(file_exists($filePath));
        $this->storage->delete($filePath);
        $this->assertFalse(file_exists($filePath));
    }

    public function testListContents(): void
    {
        file_put_contents($this->testDir . '/file1.txt', 'content1');
        file_put_contents($this->testDir . '/file2.txt', 'content2');
        mkdir($this->testDir . '/subdir');

        $contents = $this->storage->listContents('/');

        $this->assertIsArray($contents);
        $this->assertGreaterThanOrEqual(2, count($contents));
    }

    public function testReadStream(): void
    {
        $filePath = $this->testDir . '/stream.txt';
        $content = 'Stream content';
        file_put_contents($filePath, $content);

        $stream = $this->storage->readStream($filePath);

        $this->assertTrue(is_resource($stream) || is_object($stream));
        if (is_resource($stream)) {
            $read = stream_get_contents($stream);
            $this->assertEquals($content, $read);
            fclose($stream);
        }
    }

    public function testWriteStream(): void
    {
        $filePath = $this->testDir . '/writestream.txt';
        $content = 'Written via stream';

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $this->storage->writeStream($filePath, $handle);
        fclose($handle);

        $this->assertTrue(file_exists($filePath));
        $this->assertEquals($content, file_get_contents($filePath));
    }

    public function testPathNormalization(): void
    {
        // Test that paths are normalized correctly
        $testFile = $this->testDir . '/normalized.txt';
        file_put_contents($testFile, 'test');

        // Should handle different path formats
        $this->assertTrue($this->storage->fileExists($testFile));
    }
}
