<?php
namespace GuillermoMartinez\Filemanager\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

class StorageService
{
    private ?FilesystemOperator $filesystem = null;
    private array $config = [];
    private string $root = '';

    public function __construct(array $config = [], ?FilesystemOperator $filesystem = null)
    {
        $this->config = $config;
        $this->root = $config['root'] ?? '';
        if ($filesystem instanceof FilesystemOperator) {
            $this->filesystem = $filesystem;
        } else {
            // If a root path is provided, create a local adapter automatically
            if ($this->root !== '') {
                try {
                    $adapter = new LocalFilesystemAdapter($this->root);
                    $this->filesystem = new Filesystem($adapter);
                } catch (\Throwable $e) {
                    $this->filesystem = null;
                }
            }
        }
    }

    public function resolvePath(string $docRoot, string $source): string
    {
        if ($docRoot !== '') {
            return rtrim($docRoot, '/') . '/' . ltrim($source, '/');
        }
        return $source;
    }

    public function fileExists(string $path): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try { return $this->filesystem->fileExists($this->normalizePath($path)); } catch (\Throwable $e) { return false; }
        }
        return file_exists($path);
    }

    public function isFile(string $path): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try {
                return $this->fileExists($path) && ! $this->isDir($path);
            } catch (\Throwable $e) { return false; }
        }
        return is_file($path);
    }

    public function isDir(string $path): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try {
                return $this->filesystem->directoryExists($this->normalizePath($path));
            } catch (\Throwable $e) { return false; }
        }
        return is_dir($path);
    }

    public function readStream(string $path)
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            return $this->filesystem->readStream($this->normalizePath($path));
        }
        return fopen($path, 'rb');
    }

    public function delete(string $path): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try { $this->filesystem->delete($this->normalizePath($path)); return true; } catch (\Throwable $e) { return false; }
        }
        return @unlink($path);
    }

    public function createDirectory(string $path): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try { $this->filesystem->createDirectory($this->normalizePath($path)); return true; } catch (\Throwable $e) { return false; }
        }
        return @mkdir($path, 0755, true);
    }

    public function listContents(string $path): array
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try { return $this->filesystem->listContents($this->normalizePath($path))->toArray(); } catch (\Throwable $e) { return []; }
        }
        $items = [];
        if (is_dir($path)) {
            $dir = scandir($path);
            foreach ($dir as $f) { if ($f === '.' || $f === '..') continue; $items[] = $f; }
        }
        return $items;
    }

    public function writeStream(string $path, $resource): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try { $this->filesystem->writeStream($this->normalizePath($path), $resource); return true; } catch (\Throwable $e) { return false; }
        }
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $out = fopen($path, 'wb');
        if ($out === false) return false;
        while (!feof($resource)) {
            fwrite($out, fread($resource, 8192));
        }
        fclose($out);
        return true;
    }

    public function write(string $path, string $contents): bool
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            try { $this->filesystem->write($this->normalizePath($path), $contents); return true; } catch (\Throwable $e) { return false; }
        }
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return (bool) file_put_contents($path, $contents);
    }

    private function normalizePath(string $path): string
    {
        if ($this->root !== '' && strpos($path, $this->root) === 0) {
            $rel = substr($path, strlen($this->root));
            return ltrim($rel, '/');
        }
        return ltrim($path, '/');
    }
}
