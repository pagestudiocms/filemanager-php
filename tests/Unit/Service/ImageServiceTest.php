<?php
namespace GuillermoMartinez\Filemanager\Tests\Unit\Service;

use GuillermoMartinez\Filemanager\Service\ImageService;
use PHPUnit\Framework\TestCase;

class ImageServiceTest extends TestCase
{
    private ImageService $imageService;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = TEST_TEMP_DIR . '/image-test';
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }

        $this->imageService = new ImageService([
            'resize' => [
                'small' => [120, 90, true, true],
                'medium' => [340, 180, true, true],
            ],
            'quality' => 90,
        ]);
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

    /**
     * Creates a simple test image (PNG) for testing
     */
    private function createTestImage(string $filePath, int $width = 800, int $height = 600): void
    {
        $image = imagecreatetruecolor($width, $height);
        $backgroundColor = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $backgroundColor);
        
        // Draw a simple pattern
        $color = imagecolorallocate($image, 0, 0, 255);
        imagefilledrectangle($image, 50, 50, 200, 200, $color);

        imagepng($image, $filePath);
        imagedestroy($image);
    }

    public function testImageServiceInitialization(): void
    {
        $this->assertInstanceOf(ImageService::class, $this->imageService);
    }

    public function testResizeImage(): void
    {
        // Skip if GD extension not available
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }

        $sourceImage = $this->testDir . '/source.png';
        $resizedImage = $this->testDir . '/resized.png';

        $this->createTestImage($sourceImage, 800, 600);
        
        $config = [0 => 120, 1 => 90, 2 => true, 3 => true];
        $imageInfo = ['width' => 120, 'height' => 90, 'name' => 'resized.png'];

        $result = $this->imageService->resize(
            $sourceImage,
            $resizedImage,
            $imageInfo,
            $config,
            90
        );

        $this->assertTrue($result);
        $this->assertTrue(file_exists($resizedImage));
    }

    public function testResizeThrowsExceptionOnMissingSource(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }

        $this->expectException(\Exception::class);
        
        $config = [0 => 120, 1 => 90, 2 => true, 3 => true];
        $imageInfo = ['width' => 120, 'height' => 90];

        $this->imageService->resize(
            $this->testDir . '/nonexistent.png',
            $this->testDir . '/output.png',
            $imageInfo,
            $config,
            90
        );
    }

    public function testResizeWithProportionalAspect(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }

        $sourceImage = $this->testDir . '/source-aspect.png';
        $resizedImage = $this->testDir . '/resized-aspect.png';

        $this->createTestImage($sourceImage, 1024, 768);
        
        // Config: width fixed, height auto (maintain aspect ratio)
        $config = [0 => 340, 1 => null, 2 => true, 3 => null];
        $imageInfo = ['width' => 340, 'height' => 255];

        $result = $this->imageService->resize(
            $sourceImage,
            $resizedImage,
            $imageInfo,
            $config,
            90
        );

        $this->assertTrue($result);
        $this->assertTrue(file_exists($resizedImage));
    }
}
