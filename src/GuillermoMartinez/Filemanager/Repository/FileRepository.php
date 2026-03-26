<?php
namespace GuillermoMartinez\Filemanager\Repository;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use GuillermoMartinez\Filemanager\Service\StorageService;

/**
 * REMOVED: uploadAll() - Upload handling moved to Filemanager::uploadAll()
 * REMOVED: upload() - Upload handling moved to FileOperationHandler
 * REMOVED: createThumb() - Thumbnail creation moved to ImageService
 * REMOVED: delete() - File deletion moved to FileOperationHandler
 * REMOVED: rename() - File rename moved to FileOperationHandler
 * REMOVED: move() - File move moved to FileOperationHandler
 * REMOVED: clearNameFile() - Sanitization delegated to SanitizationService
 * REMOVED: removeExtension() - Delegated to SanitizationService
 *
 * FileRepository now focuses exclusively on READ-ONLY data access operations.
 */
class FileRepository
{
    private array $config;
    private ?StorageService $storage;

    /**
     * @var Logger Instance of Logger (read-only operations only)
     */
    public $logger = null;

    /**
     * Constructor - FileRepository is now a read-only data access layer
     * 
     * @param array $config Configuration array
     * @param StorageService|null $storage Optional storage backend for file existence checks
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        array $config = [], 
        ?StorageService $storage = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->storage = $storage;
        $this->logger = $logger;
        if ($this->logger) {
            $this->logger->log('info', sprintf("%s - FileRepository initialized (read-only mode)", __METHOD__));
        }
    }

    public function getFullPath(): string
    {
        if (empty($this->config['doc_root'])) return $this->config['source'];
        return rtrim($this->config['doc_root'], '/') . '/' . ltrim($this->config['source'], '/');
    }

    public function getAllFiles(string $path)
    {
        $fullpath = $this->getFullPath() . $path;
        if (file_exists($fullpath)) {
            $file = new \SplFileInfo($fullpath);
            if ($file->isDir()) {
                $r = [];
                $finder = new Finder();
                $directories = $finder->notName($this->config['folder_thumb']);
                if (!empty($this->config['folder_excludes'])) {
                    foreach ($this->config['folder_excludes'] as $value) {
                        $directories = $directories->notName($value);
                    }
                }
                $directories = $directories->notName('web.config')->notName('.htaccess')->depth(0)->sort(
                    function ($a, $b) { return $b->getMTime() - $a->getMTime(); }
                );
                $directories = $directories->in($fullpath);
                foreach ($directories as $directorie) {
                    $namefile = $directorie->getFilename();
                    if ($directorie->isDir() && $this->validNameFile($namefile, false)) {
                        $t = $this->dirInfo($directorie, $path);
                        if ($t) $r[] = $t;
                    } elseif ($directorie->isFile() && $this->validNameFile($namefile)) {
                        $t = $this->fileInfo($directorie, $path);
                        if ($t) $r[] = $t;
                    }
                }
                return $r;
            } elseif ($file->isFile()) {
                $t = $this->fileInfo($file, $path);
                if ($t) return $t;
                return null;
            } elseif ($file->isLink()) {
                return null;
            }
        }
        return null;
    }

    private function fileInfo(SplFileInfo $file, string $path)
    {
        if ($file->isReadable()) {
            $item = [
                "urlfolder" => '',
                "filename" => $file->getFilename(),
                "filetype" => $file->getExtension(),
                "isdir" => false,
                "lastmodified" => $file->getMTime(),
                "previewfull" => '',
                "preview" => '',
                "size" => $file->getSize(),
            ];
            if ($this->isImage($file)) {
                $thumb = $this->firstThumb($file, $path);
                if ($thumb) {
                    $item['preview'] = $this->config['url'] . $this->config['source'] . '/' . $this->config['folder_thumb'] . $path . $thumb;
                }
            }
            $item['previewfull'] = $this->config['url'] . $this->config['source'] . $path . $item['filename'];
            return $item;
        }
        return null;
    }

    private function dirInfo(SplFileInfo $file, string $path)
    {
        if ($file->isReadable()) {
            return [
                "urlfolder" => $path . $file->getFilename() . '/',
                "filename" => $file->getFilename(),
                "filetype" => '',
                "isdir" => true,
                "lastmodified" => $file->getMTime(),
                "preview" => '',
                "size" => $file->getSize(),
            ];
        }
        return null;
    }

    private function validNameFile(string $filename, bool $ext = true): bool
    {
        $filename = trim($filename);
        if ($filename != '.' && $filename != '..' && $filename != ' ' && preg_match_all("#^[a-zA-Z0-9-_.\\s]+$#", $filename) > 0) {
            if ($ext) return $this->validExt($filename);
            return true;
        }
        return false;
    }

    private function validExt(string $filename): bool
    {
        $exts = $this->config['ext'] ?? [];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $exts, true);
    }

    private function isImage(SplFileInfo $file): bool
    {
        $ext = strtolower($file->getExtension());
        $search = $this->config['images']['images_ext'] ?? [];
        return in_array($ext, $search, true);
    }

    /**
     * Get the first thumbnail name for an image (read-only lookup)
     * 
     * Does not create thumbnails - that responsibility belongs to ImageService.
     * Simply returns the calculated thumbnail filename for the primary resize config.
     * 
     * @param SplFileInfo $file The image file
     * @param string $path The relative path
     * @return string|null The thumbnail filename, or null if no resize config
     */
    private function firstThumb(SplFileInfo $file, string $path): ?string
    {
        $filename = $file->getFilename();
        $resizeConfigs = $this->config['images']['resize'] ?? [];
        
        if (empty($resizeConfigs)) {
            return null;
        }
        
        // Get the first resize configuration (handles both indexed and associative arrays)
        $firstConfig = reset($resizeConfigs);
        if (!is_array($firstConfig)) {
            return null;
        }
        
        // Return the name of the first resize configuration thumbnail
        // (actual thumbnail file may or may not exist - that's caller's responsibility)
        $image_info = $this->imageSizeName($path, $filename, $firstConfig);
        return $image_info['name'] ?? null;
    }

    private function imageSizeName(string $path, string $imagen, array $image_config): array
    {
        $result = ['name' => '', 'width' => 0, 'height' => 0];
        $imagen_ext = strtolower(pathinfo($imagen, PATHINFO_EXTENSION));
        $imagen_name = pathinfo($imagen, PATHINFO_FILENAME);
        if ($image_config[2] === true && $image_config[3] === true) {
            $fullpath_new = $imagen_name . '-' . $image_config[0] . 'x' . $image_config[1] . '.' . $imagen_ext;
            $result['name'] = $fullpath_new;
            $result['width'] = $image_config[0];
            $result['height'] = $image_config[1];
        } elseif ($image_config[2] === true && $image_config[3] === null) {
            $imagesize = getimagesize($this->getFullPath() . $path . $imagen);
            $imagesize_new = $this->getImageCalSize([$imagesize[0], $imagesize[1]], $image_config);
            $fullpath_new = $imagen_name . '-' . $imagesize_new[0] . 'x' . $imagesize_new[1] . '.' . $imagen_ext;
            $result['name'] = $fullpath_new;
            $result['width'] = $imagesize_new[0];
            $result['height'] = $imagesize_new[1];
        } elseif ($image_config[2] === null && $image_config[3] === true) {
            $imagesize = getimagesize($this->getFullPath() . $path . $imagen);
            $imagesize_new = $this->getImageCalSize([$imagesize[0], $imagesize[1]], $image_config);
            $fullpath_new = $imagen_name . '-' . $imagesize_new[0] . 'x' . $imagesize_new[1] . '.' . $imagen_ext;
            $result['name'] = $fullpath_new;
            $result['width'] = $imagesize_new[0];
            $result['height'] = $imagesize_new[1];
        }
        return $result;
    }

    private function getImageCalSize(array $imagesize, array $image_config): array
    {
        $r = [];
        if (count($imagesize) > 0 && isset($imagesize[0], $imagesize[1]) && count($image_config) > 0) {
            if ($image_config[2] === true && $image_config[3] === null) {
                $por = $image_config[0] / $imagesize[0];
                $height = round($imagesize[1] * $por);
                $r[0] = $image_config[0];
                $r[1] = $height;
            } elseif ($image_config[2] === null && $image_config[3] === true) {
                $por = $image_config[1] / $imagesize[1];
                $width = round($imagesize[0] * $por);
                $r[0] = $width;
                $r[1] = $image_config[1];
            } else {
                $r[0] = $image_config[0];
                $r[1] = $image_config[1];
            }
        }
        return $r;
    }

    // Additional methods (upload, delete, rename, move) may be added here following the same pattern.
}
