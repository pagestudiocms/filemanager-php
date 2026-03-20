<?php
namespace GuillermoMartinez\Filemanager\Service;

use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * FileOperationHandler - Handles file operations (upload, delete, rename, move, download)
 * 
 * Extracted from Filemanager.php to separate file operation concerns
 * Phase 1: Separation of Concerns
 */
class FileOperationHandler
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * @var InputValidator
     */
    private $validator;

    /**
     * @var SanitizationService
     */
    private $sanitizer;

    /**
     * Configuration array
     */
    private $config;

    /**
     * Callback for getting max upload size
     */
    private $maxUploadSizeCallback;

    /**
     * Callback for getting full path (eliminates back-reference)
     */
    private $getFullPathCallback;

    /**
     * Callback for setting result info (eliminates back-reference)
     */
    private $setInfoCallback;

    /**
     * Callback for getting image size name (eliminates back-reference)
     */
    private $imageSizeNameCallback;

    public function __construct(
        InputValidator $validator,
        SanitizationService $sanitizer,
        Logger $log = null,
        $config = array(),
        $getFullPathCallback = null,
        $setInfoCallback = null,
        $imageSizeNameCallback = null
    ) {
        $this->validator = $validator;
        $this->sanitizer = $sanitizer;
        $this->log = $log;
        $this->config = $config;
        $this->getFullPathCallback = $getFullPathCallback;
        $this->setInfoCallback = $setInfoCallback;
        $this->imageSizeNameCallback = $imageSizeNameCallback;
    }

    /**
     * Set callback for max upload size (to avoid circular dependencies)
     */
    public function setMaxUploadSizeCallback($callback)
    {
        $this->maxUploadSizeCallback = $callback;
    }

    /**
     * Get the full path from callback
     */
    private function getFullPath()
    {
        if ($this->getFullPathCallback && is_callable($this->getFullPathCallback)) {
            return call_user_func($this->getFullPathCallback);
        }
        return '';
    }

    /**
     * Call setInfo via callback (eliminates back-reference to Filemanager)
     */
    private function setInfo($data = array())
    {
        if ($this->setInfoCallback && is_callable($this->setInfoCallback)) {
            return call_user_func($this->setInfoCallback, $data);
        }
    }

    /**
     * Call imageSizeName via callback (eliminates back-reference to Filemanager)
     */
    private function getImageSizeName($path, $filename, $sizeConfig)
    {
        if ($this->imageSizeNameCallback && is_callable($this->imageSizeNameCallback)) {
            return call_user_func($this->imageSizeNameCallback, $path, $filename, $sizeConfig);
        }
        return array('name' => '', 'width' => 0, 'height' => 0);
    }

    /**
     * Get max upload size from callback
     */
    private function getMaxUploadFileSize()
    {
        if ($this->maxUploadSizeCallback && is_callable($this->maxUploadSizeCallback)) {
            return call_user_func($this->maxUploadSizeCallback);
        }
        return 0;
    }

    /**
     * Move an uploaded file into storage
     * 
     * @param UploadedFile $file
     * @param string $path
     * @return \SplFileInfo|null
     */
    public function upload(UploadedFile $file, $path)
    {
        if ($this->log) {
            $this->log->info('FileOperationHandler::upload - ENTER: filename=' . $file->getClientOriginalName() . ', size_kb=' . round($file->getSize() / 1024) . ', path=' . $path);
        }

        if (!$this->validator->validExt($file->getClientOriginalName())) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::upload - EXIT: invalid extension');
            }
            return null;
        }

        $max_server = $this->getMaxUploadFileSize();
        if ($file->getSize() > ($max_server * 1024 * 1024)) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::upload - EXIT: file size ' . $file->getSize() . ' exceeds server limit ' . ($max_server * 1024 * 1024));
            }
            $result = array("query" => "BE_UPLOAD_FILE_SIZE_NOT_SERVER", "params" => array($file->getSize()));
            $this->setInfo(array("msg" => $result));
            return null;
        }

        $max_config = $this->config['upload']['size_max'];
        if ($file->getSize() > ($max_config * 1024 * 1024)) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::upload - EXIT: file size ' . $file->getSize() . ' exceeds configured limit ' . ($max_config * 1024 * 1024));
            }
            $result = array("query" => "BE_UPLOAD_FILE_SIZE_NOT_PERMITIDO", "params" => array($file->getSize()));
            $this->setInfo(array("msg" => $result));
            return null;
        }

        if (!$file->isValid()) {
            if ($this->log) {
                $this->log->error('FileOperationHandler::upload - EXIT: uploaded file is not valid, error=' . $file->getError());
            }
            return null;
        }

        $dir = $this->getFullPath() . $path;
        $namefile = $file->getClientOriginalName();
        $namefile = $this->sanitizer->clearNameFile($namefile);
        $nametemp = $namefile;

        if ($this->config["upload"]["overwrite"] == false) {
            $ext = $this->validator->getExtension($namefile);
            $i = 0;
            while (true) {
                $pathnametemp = $dir . $nametemp;
                if (file_exists($pathnametemp)) {
                    if ($this->log) {
                        $this->log->debug('FileOperationHandler::upload - file exists, incrementing: ' . $pathnametemp);
                    }
                    $i++;
                    $nametemp = $this->sanitizer->removeExtension($namefile) . '_' . $i . '.' . $ext;
                } else {
                    break;
                }
            }
            if ($this->log && $i > 0) {
                $this->log->debug('FileOperationHandler::upload - renamed due to existing files: ' . $nametemp);
            }
        }

        if ($this->log) {
            $this->log->info('FileOperationHandler::upload - moving to: ' . $dir . $nametemp);
        }

        $file->move($dir, $nametemp);
        $uploadedFile = new \SplFileInfo($dir . $nametemp);

        if ($this->log) {
            $this->log->info('FileOperationHandler::upload - EXIT: SUCCESS - ' . $uploadedFile->getRealPath());
        }

        return $uploadedFile;
    }

    /**
     * Create or rename a folder
     * 
     * @param string $namefile
     * @param string $path
     * @return boolean
     */
    public function newFolder($namefile, $path)
    {
        if ($this->log) {
            $this->log->info('FileOperationHandler::newFolder - ENTER: name=' . $namefile . ', path=' . $path);
        }

        $fullpath = $this->getFullPath() . $path;
        $namefile = $this->sanitizer->clearNameFile($namefile);
        $namefile = $this->sanitizer->sanitizeNameFolder($namefile);
        
        if ($this->log) {
            $this->log->debug('FileOperationHandler::newFolder - sanitized name=' . $namefile);
        }
        
        $dir = new Filesystem();
        
        if ($dir->exists($fullpath . $namefile)) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::newFolder - EXIT: folder already exists at ' . $fullpath . $namefile);
            }
            $result = array("query" => "BE_NEW_FOLDER_EXISTED %s", "params" => array($path . $namefile));
            $this->setInfo(array("msg" => $result, "status" => 0));
            return false;
        }

        try {
            $dir->mkdir($fullpath . $namefile);
            if ($this->log) {
                $this->log->info('FileOperationHandler::newFolder - EXIT: SUCCESS - created ' . $fullpath . $namefile);
            }
        } catch (\Exception $e) {
            if ($this->log) {
                $this->log->error('FileOperationHandler::newFolder - EXIT: FAILED - ' . $e->getMessage());
            }
            return false;
        }

        $result = array("query" => "BE_NEW_FOLDER_CREATED %s", "params" => array($path . $namefile));
        $this->setInfo(array("msg" => $result, "data" => array("path" => $path, "namefile" => $namefile)));
        return true;
    }

    /**
     * Delete a file or array of files
     * 
     * @param string|array $namefiles
     * @param string $path
     * @return void
     */
    public function delete($namefiles, $path)
    {
        if ($this->log) {
            $this->log->info('FileOperationHandler::delete - path: ' . $path);
        }

        if (is_string($namefiles)) {
            $this->deleteSingleFile($namefiles, $path);
        } elseif (is_array($namefiles)) {
            $this->deleteMultipleFiles($namefiles, $path);
        }
    }

    /**
     * Delete a single file
     * 
     * @param string $namefile
     * @param string $path
     * @return void
     */
    private function deleteSingleFile($namefile, $path)
    {
        $namefile = $this->sanitizer->sanitize($namefile);
        $fullpath = $this->getFullPath() . $path;
        $namefile = $this->sanitizer->clearNameFile($namefile);

        $file = new Filesystem();

        if ($this->validator->validNameFile($namefile, false) && $file->exists($fullpath . $namefile)) {
            if ($this->log) {
                $this->log->debug('FileOperationHandler::deleteSingleFile - deleting: ' . $fullpath . $namefile);
            }

            if (is_dir($fullpath . $namefile)) {
                $file->remove($fullpath . $namefile);
                $file->remove($this->getFullPath() . '/' . $this->config['folder_thumb'] . $path . $namefile);
                $result = array("query" => "BE_DELETE_DELETED", "params" => array());
                $this->setInfo(array("msg" => $result));
                if ($this->log) {
                    $this->log->info('FileOperationHandler::deleteSingleFile - directory deleted');
                }
            } elseif ($this->validator->validNameFile($namefile) && is_file($fullpath . $namefile)) {
                $file2 = new \SplFileInfo($fullpath . $namefile);
                $filename = $file2->getFilename();
                
                // Delete thumbnails if configured
                if (isset($this->config['images']['resize']) && is_array($this->config['images']['resize'])) {
                    foreach ($this->config['images']['resize'] as $key => $value) {
                        $image_info = $this->getImageSizeName($path, $filename, $value);
                        if ($image_info['name']) {
                            $filename_new = $image_info['name'];
                            $fullpaththumb_name = $this->getFullPath() . '/' . $this->config['folder_thumb'] . $path . $filename_new;
                            $file->remove($fullpaththumb_name);
                        }
                    }
                }

                $file->remove($fullpath . $namefile);
                $result = array("query" => "BE_DELETE_DELETED", "params" => array());
                $this->setInfo(array("msg" => $result));
                if ($this->log) {
                    $this->log->info('FileOperationHandler::deleteSingleFile - file deleted');
                }
            }
        } else {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::deleteSingleFile - file not found or invalid name');
            }
            $result = array("query" => "BE_DELETE_NOT_EXIED", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
        }
    }

    /**
     * Delete multiple files
     * 
     * @param array $namefiles
     * @param string $path
     * @return void
     */
    private function deleteMultipleFiles($namefiles, $path)
    {
        if (count($namefiles) > 0) {
            if ($this->log) {
                $this->log->info('FileOperationHandler::deleteMultipleFiles - count: ' . count($namefiles));
            }

            $fullpath = $this->getFullPath() . $path;
            $data = array();

            foreach ($namefiles as $key => $namefile) {
                $file = new Filesystem();
                if ($this->validator->validNameFile($namefile, false) && $file->exists($fullpath . $namefile)) {
                    if (is_dir($fullpath . $namefile)) {
                        $file->remove($fullpath . $namefile);
                        $file->remove($this->getFullPath() . '/' . $this->config['folder_thumb'] . $path . $namefile);
                        $data[] = array("status" => 1, "namefile" => $namefile, "query" => "BE_DELETE_DELETED", "params" => array());
                    } elseif ($this->validator->validNameFile($namefile) && is_file($fullpath . $namefile)) {
                        $file2 = new \SplFileInfo($fullpath . $namefile);
                        $filename = $file2->getFilename();
                        
                        if (isset($this->config['images']['resize']) && is_array($this->config['images']['resize'])) {
                            foreach ($this->config['images']['resize'] as $key => $value) {
                                $image_info = $this->getImageSizeName($path, $filename, $value);
                                if ($image_info['name']) {
                                    $filename_new = $image_info['name'];
                                    $fullpaththumb_name = $this->getFullPath() . '/' . $this->config['folder_thumb'] . $path . $filename_new;
                                    $file->remove($fullpaththumb_name);
                                }
                            }
                        }
                        
                        $file->remove($fullpath . $namefile);
                        $data[] = array("status" => 1, "namefile" => $namefile, "query" => "BE_DELETE_DELETED", "params" => array());
                    }
                } else {
                    $data[] = array("status" => 0, "namefile" => $namefile, "query" => "BE_DELETE_NOT_EXIED", "params" => array());
                }
            }

            $this->setInfo(array("data" => $data));
            if ($this->log) {
                $this->log->info('FileOperationHandler::deleteMultipleFiles - SUCCESS');
            }
        } else {
            $result = array("query" => "BE_DELETE_NOT_EXIED", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
        }
    }

    /**
     * Rename a file or directory
     * 
     * @param string $nameold
     * @param string $namenew
     * @param string $path
     * @return void
     */
    public function rename($nameold, $namenew, $path)
    {
        if ($this->log) {
            $this->log->info('FileOperationHandler::rename - from: ' . $nameold . ', to: ' . $namenew . ', path: ' . $path);
        }

        if (!$this->validator->validNameFile($nameold, false) || !$this->validator->validNameFile($namenew, false)) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::rename - invalid filename');
            }
            $result = array("query" => "BE_RENAME_FILENAME_NOT_VALID", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
            return;
        }

        $fullpath = $this->getFullPath() . $path;
        $nameold = $this->sanitizer->clearNameFile($nameold);
        $namenew = $this->sanitizer->clearNameFile($namenew);

        $file = new Filesystem();

        if (!$file->exists($fullpath . $nameold)) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::rename - source does not exist');
            }
            $result = array("query" => "BE_RENAME_NOT_EXISTS", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
            return;
        }

        if (is_dir($fullpath . $nameold)) {
            $namenew = $this->sanitizer->sanitizeNameFolder($namenew);
            if (!$file->exists($fullpath . $namenew)) {
                $file->rename($fullpath . $nameold, $fullpath . $namenew);
                $result = array("query" => "BE_RENAME_MODIFIED", "params" => array());
                $this->setInfo(array("msg" => $result, "data" => array("namefile" => $namenew)));
                if ($this->log) {
                    $this->log->info('FileOperationHandler::rename - directory renamed SUCCESS');
                }
            } else {
                if ($this->log) {
                    $this->log->warning('FileOperationHandler::rename - target already exists');
                }
                $result = array("query" => "BE_RENAME_EXISTED", "params" => array());
                $this->setInfo(array("msg" => $result, "status" => 0));
            }
        } elseif (is_file($fullpath . $nameold)) {
            if ($this->validator->validExt($nameold)) {
                $extold = $this->validator->getExtension($nameold);
                $namenew = $namenew . '.' . $extold;

                if (!$file->exists($fullpath . $namenew)) {
                    $file2 = new \SplFileInfo($fullpath . $nameold);
                    
                    // Handle image thumbnails if applicable
                    if (isset($this->config['images']['images_ext']) && in_array($file2->getExtension(), $this->config['images']['images_ext'])) {
                        $filename = $file2->getFilename();
                        if (isset($this->config['images']['resize']) && is_array($this->config['images']['resize'])) {
                            foreach ($this->config['images']['resize'] as $key => $value) {
                                $image_info = $this->getImageSizeName($path, $filename, $value);
                                if ($image_info['name']) {
                                    $filename_old = $image_info['name'];
                                    $fullpaththumb_name = $this->getFullPath() . '/' . $this->config['folder_thumb'] . $path . $filename_old;
                                    $file->remove($fullpaththumb_name);
                                }
                            }
                        }
                    }

                    $file->rename($fullpath . $nameold, $fullpath . $namenew);
                    $result = array("query" => "BE_RENAME_MODIFIED", "params" => array());
                    $this->setInfo(array("msg" => $result, "data" => array("namefile" => $namenew)));
                    if ($this->log) {
                        $this->log->info('FileOperationHandler::rename - file renamed SUCCESS');
                    }
                } else {
                    if ($this->log) {
                        $this->log->warning('FileOperationHandler::rename - target already exists');
                    }
                    $result = array("query" => "BE_RENAME_EXISTED", "params" => array());
                    $this->setInfo(array("msg" => $result, "status" => 0));
                }
            } else {
                if ($this->log) {
                    $this->log->warning('FileOperationHandler::rename - invalid extension');
                }
                $result = array("query" => "BE_RENAME_FILENAME_NOT_VALID", "params" => array());
                $this->setInfo(array("msg" => $result, "status" => 0));
            }
        }
    }

    /**
     * Move a file or directory
     * 
     * @param string $nameold file name
     * @param string $pathnew destination directory path
     * @param string $path source directory path
     * @return void
     */
    public function move($nameold, $pathnew, $path)
    {
        if ($this->log) {
            $this->log->info('FileOperationHandler::move - file: ' . $nameold . ', from: ' . $path . ', to: ' . $pathnew);
        }

        if (!$this->validator->validNameFile($nameold, false) || !preg_match_all("#^/{1}$|^/[a-z0-9A-Z]{1}([a-z0-9A-Z-_/])*(/|[a-z0-9A-Z-_]{1,})+$#", $pathnew) > 0) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::move - invalid parameters');
            }
            $result = array("query" => "BE_MOVE_FILENAME_NOT_VALID", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
            return;
        }

        $nameold = $this->sanitizer->clearNameFile($nameold);
        $fullpath = $this->sanitizer->sanitizeNameFolder($this->getFullPath() . $path, true);
        $fullpathnew = $this->sanitizer->sanitizeNameFolder($this->getFullPath() . $pathnew, true);

        // Validation checks
        if ($fullpath == $fullpathnew || $fullpath . '/' . $nameold == $fullpathnew . '/' . $nameold) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::move - source and destination are the same');
            }
            $result = array("query" => "BE_MOVE_PATH_NOT_VALID", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
            return;
        }

        if (strpos($fullpathnew . '/' . $nameold, $fullpath . '/' . $nameold . '/') !== false) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::move - destination is child of source');
            }
            $result = array("query" => "BE_MOVE_PATH_NOT_VALID", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
            return;
        }

        $file = new Filesystem();

        if (!$file->exists($fullpath . '/' . $nameold)) {
            if ($this->log) {
                $this->log->warning('FileOperationHandler::move - source does not exist');
            }
            $result = array("query" => "BE_MOVE_NOT_EXISTS", "params" => array());
            $this->setInfo(array("msg" => $result, "status" => 0));
            return;
        }

        if (is_dir($fullpath . '/' . $nameold)) {
            if (!$file->exists($fullpathnew . '/' . $nameold)) {
                if (!$file->exists($fullpathnew)) {
                    $file->mkdir($fullpathnew);
                }
                $file->rename($fullpath . '/' . $nameold, $fullpathnew . '/' . $nameold);
                $result = array("query" => "BE_MOVE_MOVED", "params" => array());
                $fullpathnew_result = $this->sanitizer->sanitizeNameFolder($pathnew . '/' . $nameold, true);
                $this->setInfo(array("msg" => $result, "data" => array("namefile" => $fullpathnew_result)));
                if ($this->log) {
                    $this->log->info('FileOperationHandler::move - directory moved SUCCESS');
                }
            } else {
                if ($this->log) {
                    $this->log->warning('FileOperationHandler::move - target folder already exists');
                }
                $result = array("query" => "BE_MOVE_FOLDER_EXISTED", "params" => array());
                $this->setInfo(array("msg" => $result, "status" => 0));
            }
        } elseif (is_file($fullpath . '/' . $nameold)) {
            if ($this->validator->validExt($nameold)) {
                if (!$file->exists($fullpathnew . '/' . $nameold)) {
                    if (!$file->exists($fullpathnew)) {
                        $file->mkdir($fullpathnew);
                    }

                    $file2 = new \SplFileInfo($fullpath . '/' . $nameold);

                    // Handle image thumbnails if applicable
                    if (isset($this->config['images']['images_ext']) && in_array($file2->getExtension(), $this->config['images']['images_ext'])) {
                        $filename = $file2->getFilename();
                        if (isset($this->config['images']['resize']) && is_array($this->config['images']['resize'])) {
                            foreach ($this->config['images']['resize'] as $key => $value) {
                                $image_info = $this->getImageSizeName($path, $filename, $value);
                                if ($image_info['name']) {
                                    $filename_old = $image_info['name'];
                                    $fullpaththumb_name = $this->getFullPath() . '/' . $this->config['folder_thumb'] . $path . $filename_old;
                                    $file->remove($fullpaththumb_name);
                                }
                            }
                        }
                    }

                    $file->rename($fullpath . '/' . $nameold, $fullpathnew . '/' . $nameold);
                    $result = array("query" => "BE_MOVE_MOVED", "params" => array());
                    $fullpathnew_result = $this->sanitizer->sanitizeNameFolder($pathnew . '/' . $nameold, true);
                    $this->setInfo(array("msg" => $result, "data" => array("namefile" => $fullpathnew_result)));
                    if ($this->log) {
                        $this->log->info('FileOperationHandler::move - file moved SUCCESS');
                    }
                } else {
                    if ($this->log) {
                        $this->log->warning('FileOperationHandler::move - target file already exists');
                    }
                    $result = array("query" => "BE_MOVE_EXISTED", "params" => array());
                    $this->setInfo(array("msg" => $result, "status" => 0));
                }
            } else {
                if ($this->log) {
                    $this->log->warning('FileOperationHandler::move - invalid extension');
                }
                $result = array("query" => "BE_MOVE_FILENAME_NOT_VALID", "params" => array());
                $this->setInfo(array("msg" => $result, "status" => 0));
            }
        }
    }
}
?>
