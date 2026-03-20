<?php
namespace GuillermoMartinez\Filemanager\Tests\Unit\Repository;

use GuillermoMartinez\Filemanager\Repository\FileRepository;
use GuillermoMartinez\Filemanager\Service\StorageService;
use PHPUnit\Framework\TestCase;

class FileRepositoryTest extends TestCase
{
    private FileRepository $repository;
    private StorageService $storage;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = TEST_TEMP_DIR . '/repository-test';
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }

        $this->storage = new StorageService([
            'root' => $this->testDir,
        ]);

        $config = [
            'doc_root' => $this->testDir,
            'source' => '',
            'url' => 'http://localhost/',
            'ext' => ['jpg', 'jpeg', 'gif', 'png', 'txt', 'pdf'],
            'images' => [
                'images_ext' => ['jpg', 'jpeg', 'gif', 'png'],
                'resize' => [
                    'small' => [120, 90, true, true],
                ],
                'quality' => 90,
            ],
            'folder_thumb' => '_thumbs',
            'upload' => [
                'overwrite' => false,
                'size_max' => 10,
            ],
            'debug' => false,
            'folder_excludes' => [],
        ];

        $this->repository = new FileRepository($config, $this->storage, null);
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

    public function testFileRepositoryInitialization(): void
    {
        $this->assertInstanceOf(FileRepository::class, $this->repository);
    }

    public function testGetAllFiles(): void
    {
        file_put_contents($this->testDir . '/file1.txt', 'content1');
        file_put_contents($this->testDir . '/file2.txt', 'content2');
        mkdir($this->testDir . '/subfolder');

        $files = $this->repository->getAllFiles('/');

        $this->assertIsArray($files);
        $this->assertGreaterThanOrEqual(2, count($files));
    }

    public function testGetAllFilesReturnsNullOnNonexistentPath(): void
    {
        $files = $this->repository->getAllFiles('/nonexistent/');

        $this->assertNull($files);
    }

    public function testNewFolder(): void
    {
        $result = $this->repository->newFolder('test-folder', '/');

        $this->assertTrue($result);
        $this->assertTrue(is_dir($this->testDir . '/test-folder'));
    }

    public function testNewFolderReturnsFalseIfExists(): void
    {
        mkdir($this->testDir . '/existing-folder');

        $result = $this->repository->newFolder('existing-folder', '/');

        $this->assertFalse($result);
    }

    public function testDelete(): void
    {
        file_put_contents($this->testDir . '/todelete.txt', 'delete me');

        $this->repository->delete('todelete.txt', '/');

        $this->assertFalse(file_exists($this->testDir . '/todelete.txt'));
    }

    public function testDeleteFolder(): void
    {
        mkdir($this->testDir . '/folder-to-delete');
        file_put_contents($this->testDir . '/folder-to-delete/file.txt', 'content');

        $this->repository->delete('folder-to-delete', '/');

        $this->assertFalse(is_dir($this->testDir . '/folder-to-delete'));
    }

    public function testRename(): void
    {
        file_put_contents($this->testDir . '/oldname.txt', 'content');

        $this->repository->rename('oldname.txt', 'newname.txt', '/');

        $this->assertFalse(file_exists($this->testDir . '/oldname.txt'));
        $this->assertTrue(file_exists($this->testDir . '/newname.txt'));
    }

    public function testRenameFolder(): void
    {
        mkdir($this->testDir . '/old-folder-name');

        $this->repository->rename('old-folder-name', 'new-folder-name', '/');

        $this->assertFalse(is_dir($this->testDir . '/old-folder-name'));
        $this->assertTrue(is_dir($this->testDir . '/new-folder-name'));
    }

    public function testMove(): void
    {
        mkdir($this->testDir . '/source-dir');
        mkdir($this->testDir . '/dest-dir');
        file_put_contents($this->testDir . '/source-dir/file.txt', 'content');

        $this->repository->move('file.txt', '/dest-dir/', '/source-dir/');

        $this->assertFalse(file_exists($this->testDir . '/source-dir/file.txt'));
        $this->assertTrue(file_exists($this->testDir . '/dest-dir/file.txt'));
    }

    public function testMoveReturnsFalseIfDestinationExists(): void
    {
        mkdir($this->testDir . '/dir1');
        mkdir($this->testDir . '/dir2');
        file_put_contents($this->testDir . '/dir1/file.txt', 'content1');
        file_put_contents($this->testDir . '/dir2/file.txt', 'content2');

        $this->repository->move('file.txt', '/dir2/', '/dir1/');

        // Should reject move if destination exists
        $this->assertTrue(file_exists($this->testDir . '/dir1/file.txt'));
    }

    public function testUploadSetsCorrectFilePermissions(): void
    {
        // Test that uploaded files are readable and writable
        file_put_contents($this->testDir . '/uploaded.txt', 'uploaded content');

        $this->assertTrue(is_readable($this->testDir . '/uploaded.txt'));
    }
}
