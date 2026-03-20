<?php
namespace GuillermoMartinez\Filemanager;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;
use Gregwar\Image\Image as Imagen;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use GuillermoMartinez\Filemanager\Repository\FileRepository;
use GuillermoMartinez\Filemanager\Service\InputValidator;
use GuillermoMartinez\Filemanager\Service\SanitizationService;
use GuillermoMartinez\Filemanager\Service\FileOperationHandler;

class Filemanager
{
    /**
     * configurate options
     *
     * @var [array]
     */
    private $config;

    /**
     * StorageService instance
     */
    private $storage;

    private $repository;

    /**
     * InputValidator instance (Phase 1)
     */
    private $validator;

    /**
     * SanitizationService instance (Phase 1)
     */
    private $sanitizer;

    /**
     * FileOperationHandler instance (Phase 1)
     */
    private $fileOperationHandler;

    /**
     * actions = getfolder|uploadfile|newfolder|renamefile|deletefile|download
     *
     * @var string
     */
    public $action = '';

    /**
     * path directory to search
     *
     * @var string
     */
    public $path = '';

    /**
     * instance of Logger
     *
     * @var Logger
     */
    public $log = null;

    /**
     * data of return
     * data=[fileDetails|stringResult]
     * 1=ok , 0=warning
     * // query="BE_UPLOADALL_UPLOADS %s / %s" , params=[1, 2]
     *
     * @var array
     */
    private $info = array(
        "data"=>array(),
        "status"=>1,
        "msg"=>array("query"=>"","params"=>array())
        );

    /**
     * Microtime for Log
     * @var float
     */
    private $init;

    /**
     * details of file
     *
     * @var array
     */
    private $fileDetails = array(
        "urlfolder" => '',
        "filename" => "",
        "filetype" => "",
        "isdir" => false,
        "lastmodified" => "",
        "previewfull" => "",
        "preview" => "",
        "size" => "",
        );

    /**
     * Create new Filemanager
     *
     * @param array $extra overwrites settings
     */
    public function __construct($extra=array()) {
        $this->config = array(
            "doc_root" => "",
            "url" => "/",
            "source" => "userfiles", // Relative the doc root
            "debug" => false,
            "debugfile" => "log/filemanager.log",
            "require_auth" => false, // Set to true to enforce token validation in package (framework typically owns auth)
            "ext" => array("jpg","jpeg","gif","png","svg","txt","pdf","odp","ods","odt","rtf","doc","docx","xls","xlsx","ppt","pptx","csv","ogv","mp4","webm","m4v","ogg","mp3","wav","zip","rar"),
            "upload" => array(
                "number" => 5,
                "overwrite" => false,
                "size_max" => 10
                ),
            "images" => array(
                "images_ext" => array("jpg","jpeg","gif","png"),
                "resize" => array(
                    // width,height,IF TRUE crop in width ELSEIF NULL is auto,IF TRUE crop in height ELSEIF NULL is auto
                    'small' => array(120,90,true,true),
                ),
                "quality" => 100,
            ),
            "type_file" => null,
            "folder_excludes" => array(),
            "folder_thumb" => '_thumbs',
        );
        
        if(isset($_SERVER['DOCUMENT_ROOT'])) $this->config['doc_root'] = $_SERVER['DOCUMENT_ROOT'];
        if(count($extra)>0) $this->setup($extra);
        
        if($this->config['debug']){
            $this->log = new Logger('filemanager');
            $this->log->pushHandler(new StreamHandler($this->config['debugfile']));
            $this->init = microtime();
            $this->_log(__METHOD__." - BEGIN FILEMANAGER");
        }

        // Phase 1: Initialize validation and sanitization services
        $this->validator = new InputValidator($this->log, $this->config);
        $this->_log(__METHOD__." - InputValidator initialized");
        
        $this->sanitizer = new SanitizationService($this->log);
        $this->_log(__METHOD__." - SanitizationService initialized");

        // Phase 2: Initialize file type validator (real MIME detection)
        $fileTypeValidator = new \GuillermoMartinez\Filemanager\Service\FileTypeValidator($this->log, $this->config);
        $this->_log(__METHOD__." - FileTypeValidator initialized (Phase 2)");

        // Phase 2: Initialize event dispatcher for extensibility
        $eventDispatcher = new \GuillermoMartinez\Filemanager\Service\FileEventDispatcher($this->log);
        $this->_log(__METHOD__." - FileEventDispatcher initialized (Phase 2)");

        // Instantiate StorageService if storage config provided
        if (isset($this->config['storage']) && is_array($this->config['storage'])) {
            try {
                $this->storage = new \GuillermoMartinez\Filemanager\Service\StorageService($this->config['storage']);
            } catch (\Throwable $e) {
                if($this->config['debug']) $this->_log(__METHOD__.' - Failed to instantiate StorageService: '.$e->getMessage());
                $this->storage = null;
            }
        }

        // Ensure ImageService is available via config['images']['service']
        if (isset($this->config['images']) && is_array($this->config['images'])) {
            if (!isset($this->config['images']['service']) || !is_object($this->config['images']['service'])) {
                try {
                    $this->config['images']['service'] = new \GuillermoMartinez\Filemanager\Service\ImageService($this->config['images']);
                } catch (\Throwable $e) {
                    if($this->config['debug']) $this->_log(__METHOD__.' - Failed to instantiate ImageService: '.$e->getMessage());
                }
            }
        }

        // Instantiate repository facade
        try {
            $this->repository = new FileRepository($this->config, $this->storage, $this->config['images']['service'], $this->log);
        } catch (\Throwable $e) {
            if($this->config['debug']) $this->_log(__METHOD__.' - Failed to instantiate FileRepository: '.$e->getMessage());
            $this->repository = null;
        }

        // Phase 1: Initialize file operation handler with callbacks (Phase 1 refactoring)
        // Phase 2: Inject FileTypeValidator and FileEventDispatcher
        $this->fileOperationHandler = new FileOperationHandler(
            $this->validator,
            $this->sanitizer,
            $this->log,
            $this->config,
            array($this, 'getFullPath'),      // Callback for full path (eliminates back-reference)
            array($this, 'setInfo'),          // Callback for setInfo (eliminates back-reference)
            array($this, 'imageSizeName'),    // Callback for imageSizeName (eliminates back-reference)
            $fileTypeValidator,               // Phase 2: File type validator
            $eventDispatcher                  // Phase 2: Event dispatcher
        );
        $this->fileOperationHandler->setMaxUploadSizeCallback(array($this, 'getMaxUploadFileSize'));
        $this->_log(__METHOD__." - FileOperationHandler initialized with Phase 2 services");

        if($this->config['type_file']=='images') {
            $this->config['ext'] = $this->config['images']['images_ext'];
        }
    }

    /**
     * Returns the formatted time difference for logging
     * @return string
     */
    private function getLogTime()
    {
        $diff = $this->microtime_diff($this->init);
        $format = gmdate("H:i:s",(int)$diff);
        $diff = sprintf("%01.5f", $diff);
        $format .= substr($diff,strpos((string)$diff,'.'));
        return $format;
    }

    /**
     * Microtime difference
     * @param  string $start start time
     * @param  string|null $end   end time (optional)
     * @return float
     */
    private function microtime_diff($start, $end = null)
    {
        if (!$end) {
            $end = microtime();
        }
        list($start_usec, $start_sec) = explode(" ", $start);
        list($end_usec, $end_sec) = explode(" ", $end);
        $diff_sec = intval($end_sec) - intval($start_sec);
        $diff_usec = floatval($end_usec) - floatval($start_usec);
        return floatval($diff_sec) + $diff_usec;
    }
    /**
     * Merge configuration overrides
     *
     * @param array $extra Configuration values to merge
     * @return void
    */
    private function setup($extra){
        $this->config = array_replace_recursive($this->config,$extra);
    }

    /**
     * Get the absolute storage path
     *
     * @return string
    */
    public function getFullPath(){
        if($this->config['doc_root']=='')
            return $this->config['source'];
        else
            return $this->config['doc_root'].'/'.$this->config['source'];
    }

    /**
     * Delegate validation to InputValidator service (Phase 1)
     * @param string $path
     * @return boolean
     */
    public function validPath($path){
        return $this->validator->validPath($path);
    }

    /**
     * Delegate validation to InputValidator service (Phase 1)
     * @param string $filename
     * @param bool $ext
     * @return boolean
     */
    public function validNameFile($filename,$ext=true){
        return $this->validator->validNameFile($filename, $ext);
    }

    /**
     * Delegate validation to InputValidator service (Phase 1)
     * @param string $filename
     * @return boolean
     */
    public function validExt($filename){
        return $this->validator->validExt($filename);
    }

    /**
     * Add a message to the response data (used for errors or info)
     * @param array $data
     * @return void
     */
    public function setInfo($data=array()) {
        if(isset($data['data'])) $this->info['data'] = $data['data'];
        if(isset($data['status'])) $this->info['status'] = $data['status'];
        if(isset($data['msg'])) $this->info['msg'] = $data['msg'];

    }

    /**
     * Log a message when debugging is enabled
     * @param string $string Text to log
     * @param string $type Message level/type
     * @return void
     */
    public function _log($string,$type='info') {
        if($this->config['debug']){
            $string = $this->getLogTime().": ".$string;
        }
        $this->log->log($type, $string);
    }

    /**
     * Delegate sanitization to SanitizationService (Phase 1)
     * @param string $var
     * @return string
     */
    public function sanitize($var) {
        return $this->sanitizer->sanitize($var);
    }

    /**
     * Delegate sanitization to SanitizationService (Phase 1)
     * @param string $var
     * @param bool $strict
     * @return string
     */
    public function sanitizeNameFolder($var,$strict=false) {
        return $this->sanitizer->sanitizeNameFolder($var, $strict);
    }

    /**
     * Remove file extension from a filename
     * @param string $filename
     * @return string
     */
    public function removeExtension($filename)
    {
        return $this->sanitizer->removeExtension($filename);
    }

    /**
     * Get the extension from a file name
     * @param string $nameFile
     * @return string
     */
    public function getExtension($nameFile){
        return $this->validator->getExtension($nameFile);
    }

    /**
     * Get the server's maximum allowed upload file size (MB)
     * @return int
     */
    public function getMaxUploadFileSize() {

        $upload_max_filesize = (integer) ini_get('upload_max_filesize');
        $post_max_size = (integer) ini_get('post_max_size');
        $size_max = min($upload_max_filesize, $post_max_size);

        return $size_max;
    }

    /**
     * Delegate name clearing to SanitizationService (Phase 1)
     * @param string $namefile
     * @return string
     */
    public function clearNameFile($namefile){
        return $this->sanitizer->clearNameFile($namefile);
    }

    /**
    * All properties of a file or directory
    *
    * @param SplFileInfo $file Object of SplFileInfo
    * @param string $path Path of the folder or file
    * @return array|null List of properties or null if not readable
    */
    public function fileInfo($file,$path){
        if($file->isReadable()){
            $item = $this->fileDetails;
            $item["filename"] = $file->getFilename();
            $item["filetype"] = $file->getExtension();
            $item["lastmodified"] = $file->getMTime();
            $item["size"] = $file->getSize();
            if ($this->isImage($file)) {
                $thumb =  $this->firstThumb($file,$path);
                if($thumb){
                    $item['preview'] = $this->config['url'].$this->config['source'].'/'.$this->config['folder_thumb'].$path.$thumb;
                }
            }
            $item['previewfull'] = $this->config['url'].$this->config['source'].$path.$item["filename"];
            return $item;
        }else{
            return ;
        }
    }

    /**
     * Directory information
     * @param  UploadedFile $file
     * @param  string $path relative path
     * @return array
     */
    public function dirInfo($file,$path){
        if($file->isReadable()){
            $item = $this->fileDetails;
            $item["filename"] = $file->getFilename();
            $item["filetype"] = $file->getExtension();
            $item["lastmodified"] = $file->getMTime();
            $item["size"] = $file->getSize();
            $item["filetype"] = '';
            $item["isdir"] = true;
            $item["urlfolder"] = $path.$item["filename"].'/';
            $item['preview'] = '';
            return $item;
        }else{
            return ;
        }
    }

    /**
     * Information for the first thumbnail
     * @param  UploadedFile $file
     * @param  string $path relative path
     * @return string
     */
    public function firstThumb($file,$path){
        $fullpath = $this->getFullPath().$path;
        $fullpaththumb = $this->getFullPath().'/'.$this->config['folder_thumb'].$path;
        $filename = $file->getFilename();
        $filename_new_first = '';
        foreach ($this->config['images']['resize'] as $key => $value) {
            $image_info = $this->imageSizeName($path,$filename,$value);
            if(file_exists($fullpaththumb.$image_info['name']) === false){
                $this->resizeImage($fullpath.$filename,$fullpaththumb.$image_info['name'],$image_info,$value);
            }
            $filename_new_first = $image_info['name'];
            break;
        }
        return $filename_new_first;
    }

    /**
     * Validate whether given file is an image based on extension
     * @param  UploadedFile  $file
     * @return boolean
     */
    public function isImage($file){
        $ext = $file->getExtension();
        if(in_array(strtolower($ext), $this->config["images"]["images_ext"] ) )
            return true;
        else
            return false;
    }

    /**
     * Create thumbnails for an image
     * @param UploadedFile $file
     * @param string $path
     * @return string Name of the created thumbnail file
     */
    public function createThumb($file,$path){
        $ext = $file->getExtension();
        $search = $this->config["images"]["images_ext"];
        if(array_search($ext, $search) !== false){
            $fullpath = $this->getFullPath().$path;
            $fullpaththumb = $this->getFullPath().'/'.$this->config['folder_thumb'].$path;
            $filename = $file->getFilename();
            $filename_new_first = '';
            $i=0;
            foreach ($this->config['images']['resize'] as $key => $value) {
                $i++;
                $image_info = $this->imageSizeName($path,$filename,$value);
                if(file_exists($fullpaththumb.$image_info['name']) === false){
                    $this->resizeImage($fullpath.$filename,$fullpaththumb.$image_info['name'],$image_info,$value);
                }
                if($i==1) $filename_new_first = $image_info['name'];
            }
            return $filename_new_first;
        }else{
            if( $this->config['debug'] ) $this->_log(__METHOD__." - $ext");
        }
    }

    /**
     * Resize an image
     * @param  string $path_imagen     source image path
     * @param  string $path_imagen_new destination image path
     * @param  array  $image_info      image info array
     * @param  array  $image_config    image size configuration
     * @return boolean
     */
    public function resizeImage($path_imagen,$path_imagen_new,$image_info=array(),$image_config=array())
    {
        // Delegate to ImageService if provided
        if (isset($this->config['images']['service']) && is_object($this->config['images']['service'])) {
            try {
                return $this->config['images']['service']->resize($path_imagen, $path_imagen_new, $image_info, $image_config, $this->config['images']['quality'] ?? null);
            } catch (\Throwable $e) {
                if($this->config['debug']) $this->_log(__METHOD__.' - ImageService resize error: '.$e->getMessage());
                return false;
            }
        }
        // Fallback to previous implementation
        $imagen_ext = $this->getExtension($path_imagen);
        if ($image_config[2]===true && $image_config[3]===true) {
            Imagen::open($path_imagen)->zoomCrop($image_info['width'],$image_info['height'])->save($path_imagen_new,$imagen_ext,$this->config['images']['quality']);
            return true;
        }elseif ( $image_config[2]===true && $image_config[3]===null ) {
            Imagen::open($path_imagen)->scaleResize($image_info['width'],null)->save($path_imagen_new,$imagen_ext,$this->config['images']['quality']);
            return true;
        }elseif ( $image_config[2]===null && $image_config[3]===true ) {
            Imagen::open($path_imagen)->scaleResize(null,$image_info['height'])->save($path_imagen_new,$imagen_ext,$this->config['images']['quality']);
            return true;
        }else{
            return false;
        }
    }

    /**
     * Return the new image name and dimensions
     * @param  string $path         relative path
     * @param  string $imagen       image filename
     * @param  array $image_config image size configuration
     * @return array
     */
    public function imageSizeName($path,$imagen,$image_config)
    {
        $result = array("name"=>'',"width"=>0,"height"=>0);
        $imagen_ext = $this->getExtension($imagen);
        $imagen_name = $this->removeExtension($imagen);
        if ($image_config[2]===true && $image_config[3]===true) {
            $fullpath_new = $imagen_name.'-'.$image_config[0].'x'.$image_config[1].'.'.$imagen_ext;
            $result['name'] = $fullpath_new;
            $result['width'] = $image_config[0];
            $result['height'] = $image_config[1];
        }elseif ( $image_config[2]===true && $image_config[3]===null ) {
            $imagesize = getimagesize($this->getFullPath().$path.$imagen);
            $imagesize_new = $this->getImageCalSize(array($imagesize[0],$imagesize[1]),$image_config);
            $fullpath_new = $imagen_name.'-'.$imagesize_new[0].'x'.$imagesize_new[1].'.'.$imagen_ext;
            $result['name'] = $fullpath_new;
            $result['width'] = $imagesize_new[0];
            $result['height'] = $imagesize_new[1];
        }elseif ( $image_config[2]===null && $image_config[3]===true ) {
            $imagesize = getimagesize($this->getFullPath().$path.$imagen);
            $imagesize_new = $this->getImageCalSize(array($imagesize[0],$imagesize[1]),$image_config);
            $fullpath_new = $imagen_name.'-'.$imagesize_new[0].'x'.$imagesize_new[1].'.'.$imagen_ext;
            $result['name'] = $fullpath_new;
            $result['width'] = $imagesize_new[0];
            $result['height'] = $imagesize_new[1];
        }
        return $result;
    }

    /**
     * Calculate target image dimensions based on configuration
     * @param  array  $imagesize    original image dimensions
     * @param  array  $image_config image configuration
     * @return array               new image dimensions
     */
    public function getImageCalSize($imagesize=array(),$image_config=array())
    {
        $r = array();
        if(count($imagesize)>0 && isset($imagesize[0],$imagesize[1]) && count($image_config)>0){
            if ($image_config[2]===true && $image_config[3]===null) {
                $por =  $image_config[0] / $imagesize[0];
                $height = round($imagesize[1] * $por);
                $r[0] = $image_config[0];
                $r[1] = $height;
            }elseif ($image_config[2]===null && $image_config[3]===true) {
                $por =  $image_config[1] / $imagesize[1];
                $width = round($imagesize[0] * $por);
                $r[0] = $width;
                $r[1] = $image_config[1];
            }else{
                $r[0] = $image_config[0];
                $r[1] = $image_config[1];
            }
        }
        return $r;
    }

    /**
    * List folder and files for the given path (non-recursive), ordered by type and name
    *
    * @param string $path Path of the folder or file
    * @return array|null List of files or null if not found
    */
    public function getAllFiles($path){
        if ($this->repository) {
            return $this->repository->getAllFiles($path);
        }
        return null;
    }

    /**
     * Delegate upload to FileOperationHandler (Phase 1)
     * @param UploadedFile $file
     * @param string $path
     * @return SplFileInfo|null
     */
    public function upload($file,$path){
        return $this->fileOperationHandler->upload($file, $path);
    }

    /**
     * Upload all files
     * @param array $files
     * @param string $path
     * @return array|null
     */
    public function uploadAll($files,$path){
        // fallback to legacy behaviour
        if( is_array($files) && count($files) > 0 ){
            $n = count($files);
            if( $n <= $this->config['upload']['number'] ){
                $res = array();
                $notresult = array();
                foreach ($files as $key => $value) {
                    $file = $this->upload($value,$path);
                    if( $file ){
                        $this->createThumb($file,$path);
                        $res[] = $file->getFilename();
                    }else{
                        $notresult[] = $value->getClientOriginalName();
                    }
                }
                $r = '';
                $n2 = count($res);
                $result = array("query"=>"BE_UPLOADALL_UPLOADS %s / %s","params"=>array($n2,$n));
                if(count($notresult)>0){
                    $result['query'] = $result['query'].' | BE_UPLOADALL_NOT_UPLOADS ';
                    $i=0;
                    $n = count($notresult);
                    foreach ($notresult as $value) {
                        $result['query'] = $result['query'] . ' %s';
                        if( $n - 1 > $i ){
                            $result['query'] = $result['query'] . ',';
                            $value = $value.',';
                        }
                        $result['params'][] = $value;
                        $i++;

                    }
                    $this->setInfo(array("status"=>0));
                }
                $this->setInfo(array("msg"=>$result));
                return $res;
            }else{
                $result = array("query"=>"BE_UPLOAD_MAX_UPLOAD %s","params"=>array($this->config['upload']['number']));
                $this->setInfo(array("msg"=>$result,"status"=>0));
            }
        }else{
            return ;
        }
    }

    /**
     * Delegate new folder creation to FileOperationHandler (Phase 1)
     * @param string $namefile
     * @param string $path
     * @return boolean
     */
    public function newFolder($namefile,$path){
        return $this->fileOperationHandler->newFolder($namefile, $path);
    }

    /**
     * Delegate delete to FileOperationHandler (Phase 1)
     * @param string|array $namefiles
     * @param string $path
     * @return void
     */
    public function delete($namefiles,$path){
        $this->fileOperationHandler->delete($namefiles, $path);
    }

    /**
     * Delegate rename to FileOperationHandler (Phase 1)
     * @param string $nameold
     * @param string $namenew
     * @param string $path
     * @return void
     */
    public function rename($nameold,$namenew,$path){
        $this->fileOperationHandler->rename($nameold, $namenew, $path);
    }

    /**
     * Delegate move to FileOperationHandler (Phase 1)
     * @param string $nameold
     * @param string $pathnew
     * @param string $path
     * @return void
     */
    public function move($nameold,$pathnew,$path){
        $this->fileOperationHandler->move($nameold, $pathnew, $path);
    }

    /**
     * Delegate download to appropriate handler (Phase 1)
     * @param string $name
     * @param string $path
     * @return void
     */
    public function download($name,$path){
        $ruta = $this->getFullPath().$path.$name;
        if( $this->config['debug'] ) $this->_log('$ruta - '.$ruta);
        if($this->validNameFile($name) && (($this->storage && $this->storage->fileExists($ruta)) || (file_exists($ruta) && is_file($ruta)))){
            if ($this->storage && $this->storage->fileExists($ruta)) {
                $stream = $this->storage->readStream($ruta);
                $contents = '';
                if (is_resource($stream)) {
                    while (!feof($stream)) { $contents .= fread($stream, 8192); }
                    fclose($stream);
                } else {
                    $contents = '';
                }
                $response = new Response($contents);
            } else {
                $response = new Response(file_get_contents($ruta));
            }
            $response->headers->set('Content-Type', 'application/octet-stream');
            $d = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT,$name);
            $response->headers->set('Content-Disposition', $d);
            $response->send();
        }else{
            exit();
            // return null;
        }
    }

    /**
     * Phase 2: Public event API to register listeners
     * Subscribe to a file operation event
     * 
     * @param string $eventName
     * @param callable $callback
     * @param int $priority
     * @return void
     */
    public function on($eventName, callable $callback, $priority = 0)
    {
        if ($this->fileOperationHandler) {
            if ($this->log) {
                $this->log->debug('Filemanager::on - registering listener for: ' . $eventName);
            }
            $dispatcher = $this->fileOperationHandler->getEventDispatcher();
            if ($dispatcher) {
                $dispatcher->on($eventName, $callback, $priority);
            }
        }
    }

    /**
     * Phase 2: Public event API - one-time listener
     * Subscribe once to a file operation event
     * 
     * @param string $eventName
     * @param callable $callback
     * @return void
     */
    public function once($eventName, callable $callback)
    {
        if ($this->fileOperationHandler) {
            if ($this->log) {
                $this->log->debug('Filemanager::once - registering one-time listener for: ' . $eventName);
            }
            $dispatcher = $this->fileOperationHandler->getEventDispatcher();
            if ($dispatcher) {
                $dispatcher->once($eventName, $callback);
            }
        }
    }

    /**
     * Phase 2: Public event API - unsubscribe
     * Remove listener from event
     * 
     * @param string $eventName
     * @param callable $callback
     * @return void
     */
    public function off($eventName, callable $callback)
    {
        if ($this->fileOperationHandler) {
            if ($this->log) {
                $this->log->debug('Filemanager::off - removing listener from: ' . $eventName);
            }
            $dispatcher = $this->fileOperationHandler->getEventDispatcher();
            if ($dispatcher) {
                $dispatcher->off($eventName, $callback);
            }
        }
    }

    /**
     * Execute the requested actions and return JSON response
     * @return JsonResponse
     */
    public function run(){
        $request = Request::createFromGlobals();
        $request->getPathInfo();
        $jsonResponse = new JsonResponse;
        if($request->getMethod()=='POST'){
            $this->action = $this->sanitize($request->request->get('action'));
            $path = $this->sanitize($request->request->get('path'));
            if($this->validPath($path)==false){
                $result = array("query"=>"BE_RUN_NOT_VALID %s","params"=>array($path));
                $this->setInfo(array("msg"=>$result));
                if( $this->config['debug'] ) $this->_log(__METHOD__.' - No valido $path: '.$path);
            }else{
                if($this->action==='getfolder'){
                    $folders = $this->getAllFiles($path);
                    if(is_array($folders))
                        $this->setInfo(array( "data" => $folders ));
                }elseif($this->action==='uploadfile' ){
                    // $files = $this->uploadAll($request->files->get('file'),$path);
                    $files = $this->repository->uploadAll($request->files->get('file'), $path);
                    if($files)
                        $this->setInfo(array( "data" => $files ));
                }elseif($this->action==='newfolder'){
                    $name = $this->sanitize($request->request->get('name'));
                    $this->newFolder($name,$path);

                }elseif($this->action==='renamefile'){
                    $nameold = $this->sanitize($request->request->get('nameold'));
                    $namenew = $this->sanitize($request->request->get('name'));
                    $this->rename($nameold,$namenew,$path);
                }elseif($this->action==='movefile'){
                    $nameold = $this->sanitize($request->request->get('nameold'));
                    $namenew = $this->sanitize($request->request->get('name'));
                    $this->move($nameold,$namenew,$path);
                }elseif($this->action==='deletefile'){
                    $name = $request->request->get('name');
                    $this->delete($name,$path);
                }else{
                    $this->setInfo(array("status"=>0));
                }
            }
        }elseif($request->getMethod()=='GET'){
            $this->action = $this->sanitize($request->query->get('action'));
            $path = $this->sanitize($request->query->get('path'));
            $name = $this->sanitize($request->query->get('name'));
            if($this->validPath($path)==false){
                $result = array("query"=>"BE_RUN_NOT_VALID %s","params"=>array($path));
                $this->setInfo(array("msg"=>$result));
                if( $this->config['debug'] ) $this->_log(__METHOD__.' - No valido $path: '.$path);
            }else{
                if($this->action==='download'){
                    $this->download($name,$path);
                }
            }
        }
        $jsonResponse->setData($this->info);
        if( $this->config['debug'] ) $this->_log(__METHOD__." - END FILEMANAGER");
        return $jsonResponse->sendContent();
    }
}
?>
