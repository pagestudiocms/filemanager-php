<?php
namespace GuillermoMartinez\Filemanager\Service;

use Gregwar\Image\Image as Imagen;

class ImageService
{
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Resize an image using Gregwar\Image. Returns true on success.
     * @param string $source Absolute path to source image
     * @param string $dest Absolute path to destination image
     * @param array $imageInfo Calculated name/width/height
     * @param array $imageConfig Resize configuration [width,height,cropW,cropH]
     * @param int|null $quality Optional quality override
     * @return bool
     */
    public function resize(string $source, string $dest, array $imageInfo = [], array $imageConfig = [], ?int $quality = null, $logger = null): bool
    {
        try {
            $q = $quality ?? ($this->config['quality'] ?? 90);
            // $logger->log('info', sprintf("%s - Resizing image %s to %s with quality %d", __METHOD__, $source, $dest, $q));
            if ($imageConfig[2] === true && $imageConfig[3] === true) {
                Imagen::open($source)->zoomCrop($imageInfo['width'], $imageInfo['height'])->save($dest, 'guess', $q);
                return true;
            } elseif ($imageConfig[2] === true && $imageConfig[3] === null) {
                Imagen::open($source)->scaleResize($imageInfo['width'], null)->save($dest, 'guess', $q);
                return true;
            } elseif ($imageConfig[2] === null && $imageConfig[3] === true) {
                Imagen::open($source)->scaleResize(null, $imageInfo['height'])->save($dest, 'guess', $q);
                return true;
            }
        } catch (\Throwable $e) {
            // $logger->log('error', sprintf("%s - Error resizing image %s: %s", __METHOD__, $source, $e->getMessage()));
        }
        return false;
    }
}
