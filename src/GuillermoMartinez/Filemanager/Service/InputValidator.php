<?php
namespace GuillermoMartinez\Filemanager\Service;

use Monolog\Logger;

/**
 * InputValidator - Validates file paths, filenames, and extensions
 * 
 * Extracted from Filemanager.php to separate validation concerns
 * Phase 1: Separation of Concerns
 */
class InputValidator
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * Configuration array containing allowed extensions
     */
    private $config;

    public function __construct(Logger $log = null, $config = array())
    {
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * Validate the URL path (only formats like /directory/ or /file.txt)
     * 
     * @param string $path
     * @return boolean
     */
    public function validPath($path)
    {
        if ($this->log) {
            $this->log->debug('InputValidator::validPath - ENTER: ' . $path);
        }
        
        if (preg_match_all("#^/{1}$|^/[a-z0-9A-Z]{1}([a-z0-9A-Z-._/])*(/|[a-z0-9A-Z-_]+[.]{1}[a-z0-9A-Z]{1,})+$#", $path) > 0) {
            if ($this->log) {
                $this->log->debug('InputValidator::validPath - EXIT: VALID');
            }
            return true;
        } else {
            if ($this->log) {
                $this->log->warning('InputValidator::validPath - EXIT: INVALID path pattern - ' . $path);
            }
            return false;
        }
    }

    /**
     * Validate a file name
     * 
     * @param string $filename
     * @param bool $ext Whether to validate extension
     * @return boolean
     */
    public function validNameFile($filename, $ext = true)
    {
        if ($this->log) {
            $this->log->debug('InputValidator::validNameFile - ENTER: ' . $filename . ', ext_check=' . ($ext ? 'true' : 'false'));
        }
        
        $filename = trim($filename);
        
        if ($filename != "." && $filename != ".." && $filename != " " && preg_match_all("#^[a-zA-Z0-9-_.\s]+$#", $filename) > 0) {
            if ($ext) {
                if ($this->log) {
                    $this->log->debug('InputValidator::validNameFile - pattern match OK, checking extension');
                }
                return $this->validExt($filename);
            } else {
                if ($this->log) {
                    $this->log->debug('InputValidator::validNameFile - EXIT: VALID (no ext check)');
                }
                return true;
            }
        } else {
            if ($this->log) {
                $this->log->warning('InputValidator::validNameFile - EXIT: INVALID pattern: ' . $filename);
            }
            return false;
        }
    }

    /**
     * Validate a file extension against configured allowed extensions
     * 
     * @param string $filename
     * @return boolean
     */
    public function validExt($filename)
    {
        if ($this->log) {
            $this->log->debug('InputValidator::validExt - ENTER: ' . $filename);
        }
        
        if (empty($this->config) || !isset($this->config['ext'])) {
            if ($this->log) {
                $this->log->error('InputValidator::validExt - EXIT: no extension config available');
            }
            return false;
        }

        $exts = $this->config['ext'];
        $ext = $this->getExtension($filename);
        
        if ($this->log) {
            $this->log->debug('InputValidator::validExt - extracted extension: ' . $ext . ', allowed count: ' . count($exts));
        }
        
        if (array_search($ext, $exts) === false) {
            if ($this->log) {
                $this->log->warning('InputValidator::validExt - EXIT: INVALID extension: ' . $ext);
            }
            return false;
        } else {
            if ($this->log) {
                $this->log->debug('InputValidator::validExt - EXIT: VALID extension: ' . $ext);
            }
            return true;
        }
    }

    /**
     * Get the extension from a file name
     * 
     * @param string $nameFile
     * @return string
     */
    public function getExtension($nameFile)
    {
        if ($this->log) {
            $this->log->debug('InputValidator::getExtension - ENTER: ' . $nameFile);
        }
        
        $extension = substr($nameFile, (strrpos($nameFile, '.') + 1));
        $extension = strtolower($extension);
        
        if ($this->log) {
            $this->log->debug('InputValidator::getExtension - EXIT: ' . $extension);
        }
        
        return $extension;
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
