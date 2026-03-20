<?php
namespace GuillermoMartinez\Filemanager\Repository;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use GuillermoMartinez\Filemanager\Service\StorageService;
use GuillermoMartinez\Filemanager\Service\ImageService;

class FileRepository
{
    private array $config;
    private ?StorageService $storage;
    private ?ImageService $imageService;

    /**
     * @var Logger Instance of Logger
     */
    public $logger = null;

    public function __construct(
        array $config = [], 
        ?StorageService $storage = null, 
        ?ImageService $imageService = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->storage = $storage;
        $this->imageService = $imageService;
        $this->logger = $logger;
        $this->logger->log('info', sprintf("%s - FileRepository initialized", __METHOD__));
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

    public function uploadAll($files, $path)
    {
        if (!is_array($files) || count($files) === 0) return null;
        $n = count($files);
        if ($n > ($this->config['upload']['number'] ?? 5)) {
            return ['status'=>0,'msg'=>['query'=>'BE_UPLOAD_MAX_UPLOAD','params'=>[$this->config['upload']['number'] ?? 5]]];
        }
        $res = [];
        $notresult = [];
        foreach ($files as $file) {
            $uploaded = $this->upload($file, $path);
            if ($uploaded) {
                // create thumb
                $this->createThumb($uploaded, $path);
                $res[] = $uploaded;
            } else {
                $notresult[] = $file->getClientOriginalName();
            }
        }
        return ['uploaded'=>$res,'failed'=>$notresult];
    }

    public function upload($file, $path)
    {
        // $file is Symfony\Component\HttpFoundation\File\UploadedFile
        if (!$file || !method_exists($file, 'getClientOriginalName')) return null;
        $original = $file->getClientOriginalName();
        if (!$this->validExt($original)) return null;
        $size = $file->getSize();
        $serverMax = min((int) ini_get('upload_max_filesize'), (int) ini_get('post_max_size'));
        if ($size > ($serverMax * 1024 * 1024)) return null;
        if (isset($this->config['upload']['size_max']) && $size > ($this->config['upload']['size_max'] * 1024 * 1024)) return null;

        $namefile = $this->clearNameFile($original);
        $nametemp = $namefile;
        if (empty($this->config['upload']['overwrite'])) {
            $ext = pathinfo($namefile, PATHINFO_EXTENSION);
            $i = 0;
            while (true) {
                $pathnametemp = $this->getFullPath() . $path . $nametemp;
                if ($this->storage && $this->storage->fileExists($pathnametemp)) {
                    $i++; $nametemp = $this->removeExtension($namefile) . '_' . $i . '.' . $ext;
                    continue;
                } elseif (file_exists($pathnametemp)) {
                    $i++; $nametemp = $this->removeExtension($namefile) . '_' . $i . '.' . $ext;
                    continue;
                }
                break;
            }
        }

        $destRelative = ltrim($this->config['source'] . $path . $nametemp, '/');
        $destAbsolute = $this->getFullPath() . $path . $nametemp;

        if ($this->storage) {
            // ensure directory
            $this->storage->createDirectory(ltrim($this->config['source'] . $path, '/'));
            $stream = fopen($file->getRealPath(), 'rb');
            $ok = $this->storage->writeStream($destAbsolute, $stream);
            if (is_resource($stream)) fclose($stream);
            if ($ok) {
                // return filename
                return $nametemp;
            }
            return null;
        }

        // Fallback: move uploaded file to destination
        $dir = $this->getFullPath() . $path;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file->move($dir, $nametemp);
        return $nametemp;
    }

    private function clearNameFile(string $namefile): string
    {
        $namefile = strip_tags($namefile);
        $namefile = trim($namefile);
        $buscar = ["á","é","í","ó","ú","ñ","Ñ","Á","É","Í","Ó","Ú","ü","Ü"];
        $reemplazar = ["a","e","i","o","u","n","n","a","e","i","o","u","u","U"];
        $namefile = str_replace($buscar, $reemplazar, $namefile);
        $namefile = preg_replace("/[\s]+/", '-', $namefile);
        $namefile = preg_replace("/[^a-zA-Z0-9._-]/", '', $namefile);
        $namefile = strtolower($namefile);
        return $namefile;
    }

    private function removeExtension($filename)
    {
        return substr($filename, 0, strrpos($filename, '.'));
    }

    private function createThumb($filename, $path)
    {
        $fullpath = $this->getFullPath() . $path;
        $fullpaththumb = $this->getFullPath() . '/' . ($this->config['folder_thumb'] ?? '_thumbs') . $path;
        foreach ($this->config['images']['resize'] as $value) {
            $image_info = $this->imageSizeName($path, $filename, $value);
            if ($this->imageService) {
                $imageUploaded = $this->imageService->resize(
                    $fullpath . $filename, 
                    $fullpaththumb . $image_info['name'], 
                    $image_info, 
                    $value, 
                    $this->config['images']['quality'] ?? null, 
                    $this->logger
                );
                $this->logger->log('info', sprintf("%s - %s thumbnail for %s: %s", __METHOD__, $imageUploaded ? 'Created' : 'Failed to create', $filename, $image_info['name']));
            }
        }
    }

    public function delete($namefiles, $path)
    {
        $fullpath = $this->getFullPath() . $path;
        $fs = new Filesystem();
        $results = [];
        if (is_string($namefiles)) $namefiles = [$namefiles];
        foreach ($namefiles as $namefile) {
            if (!$this->validNameFile($namefile, false)) { $results[] = ['status'=>0,'namefile'=>$namefile]; continue; }
            $target = $fullpath . $namefile;
            if ($this->storage && $this->storage->fileExists($target)) {
                $this->storage->delete($target);
                $results[] = ['status'=>1,'namefile'=>$namefile];
                continue;
            }
            if ($fs->exists($target)) {
                if (is_dir($target)) {
                    $fs->remove($target);
                } else {
                    $fs->remove($target);
                }
                $results[] = ['status'=>1,'namefile'=>$namefile];
            } else {
                $results[] = ['status'=>0,'namefile'=>$namefile];
            }
        }
        return $results;
    }

    public function rename($nameold, $namenew, $path)
    {
        $fullpath = $this->getFullPath() . $path;
        $fs = new Filesystem();
        $nameold = $this->clearNameFile($nameold);
        $namenew = $this->clearNameFile($namenew);
        if ($fs->exists($fullpath . $nameold)) {
            $fs->rename($fullpath . $nameold, $fullpath . $namenew);
            return true;
        }
        return false;
    }

    public function move($nameold, $pathnew, $path)
    {
        $fullpath = $this->getFullPath() . $path;
        $fullpathnew = $this->getFullPath() . $pathnew;
        $fs = new Filesystem();
        if ($fs->exists($fullpath . $nameold)) {
            if (!$fs->exists($fullpathnew)) $fs->mkdir($fullpathnew);
            $fs->rename($fullpath . $nameold, $fullpathnew . '/' . $nameold);
            return true;
        }
        return false;
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

    private function firstThumb(SplFileInfo $file, string $path): ?string
    {
        $fullpath = $this->getFullPath() . $path;
        $fullpaththumb = $this->getFullPath() . '/' . $this->config['folder_thumb'] . $path;
        $filename = $file->getFilename();
        foreach ($this->config['images']['resize'] as $key => $value) {
            $image_info = $this->imageSizeName($path, $filename, $value);
            if (!file_exists($fullpaththumb . $image_info['name'])) {
                // create via imageService if available
                if ($this->imageService) {
                    $this->imageService->resize($fullpath . $filename, $fullpaththumb . $image_info['name'], $image_info, $value, $this->config['images']['quality'] ?? null);
                } else {
                    // best-effort fallback
                    @mkdir(dirname($fullpaththumb . $image_info['name']), 0755, true);
                }
            }
            return $image_info['name'];
        }
        return null;
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
