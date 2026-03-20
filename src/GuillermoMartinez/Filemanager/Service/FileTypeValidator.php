<?php
namespace GuillermoMartinez\Filemanager\Service;

use Monolog\Logger;

/**
 * FileTypeValidator - Validates file types using real content inspection
 * 
 * Phase 2: Replaces extension-based validation with actual file content verification
 * Uses finfo to detect MIME types instead of relying on file extensions
 */
class FileTypeValidator
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * Configuration array with allowed MIME types
     */
    private $config;

    /**
     * Mapping of extensions to allowed MIME types
     */
    private $mimeTypeMap = array(
        'jpg' => array('image/jpeg', 'image/jpg'),
        'jpeg' => array('image/jpeg', 'image/jpg'),
        'gif' => array('image/gif'),
        'png' => array('image/png'),
        'svg' => array('image/svg+xml'),
        'txt' => array('text/plain'),
        'pdf' => array('application/pdf'),
        'odp' => array('application/vnd.oasis.opendocument.presentation'),
        'ods' => array('application/vnd.oasis.opendocument.spreadsheet'),
        'odt' => array('application/vnd.oasis.opendocument.text'),
        'rtf' => array('text/rtf', 'application/rtf'),
        'doc' => array('application/msword'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        'xls' => array('application/vnd.ms-excel'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        'ppt' => array('application/vnd.ms-powerpoint'),
        'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation'),
        'csv' => array('text/csv', 'text/plain'),
        'ogv' => array('video/ogg'),
        'mp4' => array('video/mp4'),
        'webm' => array('video/webm'),
        'm4v' => array('video/mp4'),
        'ogg' => array('audio/ogg', 'audio/vorbis'),
        'mp3' => array('audio/mpeg'),
        'wav' => array('audio/wav', 'audio/x-wav'),
        'zip' => array('application/zip', 'application/x-zip-compressed'),
        'rar' => array('application/x-rar-compressed'),
    );

    public function __construct(Logger $log = null, $config = array())
    {
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * Validate file type by actual MIME detection (not just extension)
     * 
     * @param string $filePath Full file path to validate
     * @return boolean True if file type is allowed
     */
    public function isValidFileType($filePath)
    {
        if ($this->log) {
            $this->log->debug('FileTypeValidator::isValidFileType - ENTER: ' . $filePath);
        }

        if (!file_exists($filePath)) {
            if ($this->log) {
                $this->log->warning('FileTypeValidator::isValidFileType - EXIT: file not found');
            }
            return false;
        }

        $detectedMimeType = $this->detectMimeType($filePath);
        
        if ($this->log) {
            $this->log->debug('FileTypeValidator::isValidFileType - detected MIME: ' . $detectedMimeType);
        }

        $allowedMimes = $this->getAllowedMimeTypes();
        
        if (!in_array($detectedMimeType, $allowedMimes)) {
            if ($this->log) {
                $this->log->warning('FileTypeValidator::isValidFileType - EXIT: MIME type not allowed - ' . $detectedMimeType);
            }
            return false;
        }

        if ($this->log) {
            $this->log->debug('FileTypeValidator::isValidFileType - EXIT: VALID');
        }

        return true;
    }

    /**
     * Detect actual MIME type of a file using finfo
     * 
     * @param string $filePath
     * @return string MIME type or 'application/octet-stream' if detection fails
     */
    private function detectMimeType($filePath)
    {
        if ($this->log) {
            $this->log->debug('FileTypeValidator::detectMimeType - ENTER: ' . $filePath);
        }

        // Try finfo first (most reliable)
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mimeType) {
                    if ($this->log) {
                        $this->log->debug('FileTypeValidator::detectMimeType - finfo detected: ' . $mimeType);
                    }
                    return $mimeType;
                }
            }
        }

        // Fallback to mime_content_type if available
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType) {
                if ($this->log) {
                    $this->log->debug('FileTypeValidator::detectMimeType - mime_content_type detected: ' . $mimeType);
                }
                return $mimeType;
            }
        }

        // Fallback: try to detect from extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (isset($this->mimeTypeMap[$extension])) {
            $defaultMime = $this->mimeTypeMap[$extension][0];
            if ($this->log) {
                $this->log->debug('FileTypeValidator::detectMimeType - extension-based fallback: ' . $defaultMime);
            }
            return $defaultMime;
        }

        if ($this->log) {
            $this->log->warning('FileTypeValidator::detectMimeType - could not detect MIME, returning application/octet-stream');
        }

        return 'application/octet-stream';
    }

    /**
     * Get all allowed MIME types based on config
     * 
     * @return array
     */
    private function getAllowedMimeTypes()
    {
        if ($this->log) {
            $this->log->debug('FileTypeValidator::getAllowedMimeTypes - ENTER');
        }

        $allowedMimes = array();
        $allowedExts = isset($this->config['ext']) ? $this->config['ext'] : array();

        foreach ($allowedExts as $ext) {
            $ext = strtolower($ext);
            if (isset($this->mimeTypeMap[$ext])) {
                $allowedMimes = array_merge($allowedMimes, $this->mimeTypeMap[$ext]);
            }
        }

        $allowedMimes = array_unique($allowedMimes);
        
        if ($this->log) {
            $this->log->debug('FileTypeValidator::getAllowedMimeTypes - EXIT: count=' . count($allowedMimes));
        }

        return $allowedMimes;
    }

    /**
     * Check if a MIME type is allowed for images
     * 
     * @param string $mimeType
     * @return boolean
     */
    public function isAllowedImageMimeType($mimeType)
    {
        if ($this->log) {
            $this->log->debug('FileTypeValidator::isAllowedImageMimeType - ENTER: ' . $mimeType);
        }

        $allowedImageMimes = array('image/jpeg', 'image/jpg', 'image/gif', 'image/png', 'image/svg+xml');
        
        $isAllowed = in_array($mimeType, $allowedImageMimes);
        
        if ($this->log) {
            $this->log->debug('FileTypeValidator::isAllowedImageMimeType - EXIT: ' . ($isAllowed ? 'ALLOWED' : 'NOT_ALLOWED'));
        }

        return $isAllowed;
    }

    /**
     * Update configuration
     * 
     * @param array $config
     * @return void
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }
}
?>
