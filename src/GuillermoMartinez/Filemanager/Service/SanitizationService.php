<?php
namespace GuillermoMartinez\Filemanager\Service;

use Monolog\Logger;

/**
 * SanitizationService - Sanitizes user input and filenames
 * 
 * Extracted from Filemanager.php to separate sanitization concerns
 * Phase 1: Separation of Concerns
 */
class SanitizationService
{
    /**
     * @var Logger
     */
    private $log;

    public function __construct(Logger $log = null)
    {
        $this->log = $log;
    }

    /**
     * Sanitize a string value (remove tags, dangerous protocols)
     * 
     * @param string $var
     * @return string
     */
    public function sanitize($var)
    {
        if ($this->log) {
            $this->log->debug('SanitizationService::sanitize - ENTER: input_len=' . strlen($var));
        }

        $sanitized = strip_tags($var);
        $sanitized = str_replace('http://', '', $sanitized);
        $sanitized = str_replace('https://', '', $sanitized);
        $sanitized = str_replace('../', '', $sanitized);
        $sanitized = str_replace('./', '', $sanitized);

        if ($this->log) {
            $this->log->debug('SanitizationService::sanitize - EXIT: output_len=' . strlen($sanitized) . ', removed_http=' . ($var !== $sanitized ? 'yes' : 'no'));
        }

        return $sanitized;
    }

    /**
     * Sanitize a folder name (remove tags, dots, multiple slashes)
     * 
     * @param string $var
     * @param bool $strict If true, remove trailing slash
     * @return string
     */
    public function sanitizeNameFolder($var, $strict = false)
    {
        if ($this->log) {
            $this->log->debug('SanitizationService::sanitizeNameFolder - input: ' . $var . ', strict: ' . ($strict ? 'true' : 'false'));
        }

        $sanitized = strip_tags($var);
        $sanitized = str_replace('.', '', $sanitized);
        $sanitized = preg_replace('@(\/)+@', '/', $sanitized);

        if ($strict) {
            if (substr($sanitized, -1) == '/') {
                $sanitized = substr($sanitized, 0, -1);
            }
        }

        if ($this->log) {
            $this->log->debug('SanitizationService::sanitizeNameFolder - output: ' . $sanitized);
        }

        return $sanitized;
    }

    /**
     * Remove file extension from a filename
     * 
     * @param string $filename
     * @return string
     */
    public function removeExtension($filename)
    {
        if ($this->log) {
            $this->log->debug('SanitizationService::removeExtension - ENTER: ' . $filename);
        }
        
        $result = substr($filename, 0, strrpos($filename, '.'));
        
        if ($this->log) {
            $this->log->debug('SanitizationService::removeExtension - EXIT: ' . $result);
        }
        
        return $result;
    }

    /**
     * Clean and normalize a file name (remove accents, convert spaces, lowercase, etc.)
     * 
     * @param string $namefile
     * @return string
     */
    public function clearNameFile($namefile)
    {
        if ($this->log) {
            $this->log->debug('SanitizationService::clearNameFile - ENTER: ' . $namefile);
        }

        $namefile = strip_tags($namefile);
        $namefile = trim($namefile);

        // Remove accents
        $buscar = array("á", "é", "í", "ó", "ú", "ñ", "Ñ", "Á", "É", "Í", "Ó", "Ú", "ü", "Ü");
        $reemplazar = array("a", "e", "i", "o", "u", "n", "n", "a", "e", "i", "o", "u", "u", "U");
        $namefile = str_replace($buscar, $reemplazar, $namefile);
        
        if ($this->log) {
            $this->log->debug('SanitizationService::clearNameFile - accents removed');
        }

        // Replace spaces with dashes
        $namefile = preg_replace("/[\s]+/", '-', $namefile);

        // Remove special characters except dot, dash, underscore
        $namefile = preg_replace("/[^a-zA-Z0-9._-]/", '', $namefile);

        $namefile = strtolower($namefile);

        if ($this->log) {
            $this->log->debug('SanitizationService::clearNameFile - EXIT: ' . $namefile);
        }

        return $namefile;
    }
}
?>
