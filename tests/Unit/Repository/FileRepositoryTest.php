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

    /**
     * Verify FileRepository contains only read-only methods
     * 
     * Write operations (upload, delete, rename, move, createThumb)
     * have been delegated to FileOperationHandler and ImageService.
     * FileRepository now focuses exclusively on data access concerns.
     */
    public function testFileRepositoryIsReadOnlyDataAccessLayer(): void
    {
        // Verify write operation methods no longer exist
        $this->assertFalse(method_exists($this->repository, 'uploadAll'));
        $this->assertFalse(method_exists($this->repository, 'upload'));
        $this->assertFalse(method_exists($this->repository, 'delete'));
        $this->assertFalse(method_exists($this->repository, 'rename'));
        $this->assertFalse(method_exists($this->repository, 'move'));
        $this->assertFalse(method_exists($this->repository, 'createThumb'));
        $this->assertFalse(method_exists($this->repository, 'clearNameFile'));
        $this->assertFalse(method_exists($this->repository, 'removeExtension'));

        // Verify read-only methods still exist
        $this->assertTrue(method_exists($this->repository, 'getAllFiles'));
        $this->assertTrue(method_exists($this->repository, 'getFullPath'));
        $this->assertTrue(method_exists($this->repository, 'fileInfo'));
        $this->assertTrue(method_exists($this->repository, 'dirInfo'));
        $this->assertTrue(method_exists($this->repository, 'validNameFile'));
        $this->assertTrue(method_exists($this->repository, 'validExt'));
        $this->assertTrue(method_exists($this->repository, 'isImage'));
    }
}
