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
     * Validate the URL path (only formats like /directory/ or /file.txt)
     * @param string $path
     * @return boolean
     */
    public function validPath($path){
        if(preg_match_all("#^/{1}$|^/[a-z0-9A-Z]{1}([a-z0-9A-Z-._/])*(/|[a-z0-9A-Z-_]+[.]{1}[a-z0-9A-Z]{1,})+$#",$path) > 0){
            return true;
        }else{
            return false;
        }
    }

    /**
     * Validate a file name
     * @param string $filename
     * @param bool $ext Whether to validate extension
     * @return boolean
     */
    public function validNameFile($filename,$ext=true){
        $filename = trim($filename);
        if($filename!="." && $filename!=".." && $filename!=" " && preg_match_all("#^[a-zA-Z0-9-_.\s]+$#",$filename) > 0){
            if($ext) return $this->validExt($filename);
            else return true;
        }else{
            return false;
        }
    }

    /**
     * Validate a file extension against configured allowed extensions
     * @param string $filename
     * @return boolean
     */
    public function validExt($filename){
        $exts = $this->config['ext'];
        $ext = $this->getExtension($filename);
        if(array_search($ext,$exts)===false){
            return false;
        }else{
            return true;
        }


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
     * Sanitize a string value
     * @param string $var
     * @return string
     */
    private function sanitize($var) {
        $sanitized = strip_tags($var);
        $sanitized = str_replace('http://', '', $sanitized);
        $sanitized = str_replace('https://', '', $sanitized);
        $sanitized = str_replace('../', '', $sanitized);
        $sanitized = str_replace('./', '', $sanitized);
        return $sanitized;
    }

    /**
     * Sanitize a folder name
     * @param string $var
     * @param bool $strict If true, remove trailing slash
     * @return string
     */
    private function sanitizeNameFolder($var,$strict=false) {
        $sanitized = strip_tags($var);
        $sanitized = str_replace('.', '', $sanitized);
        $sanitized = preg_replace('@(\/)+@', '/', $sanitized);
        if($strict){
            if(substr($sanitized,-1)=='/') $sanitized = substr($sanitized,0,-1);
        }
        return $sanitized;
    }

    /**
     * Remove file extension from a filename
     * @param string $filename
     * @return string
     */
    public function removeExtension($filename)
    {
        return substr($filename,0,strrpos( $filename, '.' ) ) ;
    }

    /**
     * Get the extension from a file name
     * @param string $nameFile
     * @return string
     */
    public function getExtension($nameFile){
        $extension = substr( $nameFile, ( strrpos($nameFile, '.') + 1 ) ) ;
        $extension = strtolower( $extension ) ;
        return $extension;
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
     * Clean and normalize a file name
     * @param string $namefile
     * @return string
     */
    public function clearNameFile($namefile){
        $namefile = strip_tags($namefile);
        $namefile = trim($namefile);
        $buscar = array("á","é","í","ó","ú","ñ","Ñ","Á","É","Í","Ó","Ú","ü","Ü");
        $reemplazar = array("a","e","i","o","u","n","n","a","e","i","o","u","u","U");
        $namefile = str_replace($buscar,$reemplazar,$namefile);
        $namefile = preg_replace("/[\s]+/", '-', $namefile);
        $namefile = preg_replace("/[^a-zA-Z0-9._-]/", '', $namefile);
        $namefile = strtolower($namefile);
        return $namefile;
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
     * Move an uploaded file into storage
     * @param UploadedFile $file
     * @param string $path
     * @return SplFileInfo|null
     */
    public function upload($file,$path){
        if( $this->validExt($file->getClientOriginalName())){
            if($file->getSize() > ($this->getMaxUploadFileSize() * 1024 * 1024) ){

                $result = array("query"=>"BE_UPLOAD_FILE_SIZE_NOT_SERVER","params"=>array($file->getSize()));
                $this->setInfo(array("msg"=>$result));

                if( $this->config['debug'] ) $this->_log(__METHOD__." - file size no permitido server: ".$file->getSize());
                return ;
            }elseif($file->getSize() > ($this->config['upload']['size_max'] * 1024 * 1024) ){

                $result = array("query"=>"BE_UPLOAD_FILE_SIZE_NOT_PERMITIDO","params"=>array($file->getSize()));
                $this->setInfo(array("msg"=>$result));

                if( $this->config['debug'] ) $this->_log(__METHOD__." - file size no permitido: ".$file->getSize());
                return ;
            }else{
                if($file->isValid()){
                    $dir = $this->getFullPath().$path;
                    $namefile = $file->getClientOriginalName();
                    $namefile = $this->clearNameFile($namefile);
                    $nametemp = $namefile;
                    if( $this->config["upload"]["overwrite"] ==false){
                        $ext = $this->getExtension($namefile);
                        $i=0;
                        while(true){
                            $pathnametemp = $dir.$nametemp;
                            if(file_exists($pathnametemp)){
                                $i++;
                                $nametemp = $this->removeExtension( $namefile ) . '_' . $i . '.' . $ext ;
                            }else{
                                break;
                            }
                        }
                    }
                    $file->move($dir,$nametemp);
                    $file = new  \SplFileInfo($dir.$nametemp);
                    return $file;
                }
            }
        }else{
            if( $this->config['debug'] ) $this->_log(__METHOD__." - file extension no permitido: ".$file->getExtension());
        }
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
     * Create or rename a folder
     * @param string $namefile
     * @param string $path
     * @return boolean
     */
    public function newFolder($namefile,$path){
        if ($this->repository && method_exists($this->repository, 'newFolder')) {
            return $this->repository->newFolder($namefile, $path);
        }
        $fullpath = $this->getFullPath().$path;
        $namefile = $this->clearNameFile($namefile);
        $namefile = $this->sanitizeNameFolder($namefile);
        $dir = new Filesystem;
        if($dir->exists($fullpath.$namefile)){
            $result = array("query"=>"BE_NEW_FOLDER_EXISTED %s","params"=>array($path.$namefile));
            $this->setInfo(array("msg"=>$result,"status"=>0));
            if( $this->config['debug'] ) $this->_log(__METHOD__." - Ya existe: ".$path.$namefile);
            return false;
        }else{
            $dir->mkdir($fullpath.$namefile);
            $result = array("query"=>"BE_NEW_FOLDER_CREATED %s","params"=>array($path.$namefile));
            $this->setInfo(array("msg"=>$result,"data"=>array( "path" => $path, "namefile" => $namefile )));
            return true;
        }
    }

    /**
     * Delete a file or array of files
     * @param string|array $namefiles
     * @param string $path
     * @return void
     */
    public function delete($namefiles,$path){
        if ($this->repository && method_exists($this->repository, 'delete')) {
            return $this->repository->delete($namefiles, $path);
        }

        if(is_string($namefiles)){
            $namefile = $this->sanitize($namefiles);
            $fullpath = $this->getFullPath().$path;
            $namefile = $this->clearNameFile($namefile);
            $file = new Filesystem;
            if($this->validNameFile($namefile,false) && ($file->exists($fullpath.$namefile) || ($this->storage && $this->storage->fileExists($fullpath.$namefile)))){
                if( $this->config['debug'] ) $this->_log('$fullpath.$namefile - '.$fullpath.$namefile);
                if(is_dir($fullpath.$namefile)){
                    $file->remove($fullpath.$namefile);
                    $file->remove($this->getFullPath().'/'.$this->config['folder_thumb'].$path.$namefile);
                    $result = array("query"=>"BE_DELETE_DELETED","params"=>array());
                    $this->setInfo(array("msg"=>$result));
                }elseif($this->validNameFile($namefile) && is_file($fullpath.$namefile)){
                    $file2 = new \SplFileInfo($fullpath.$namefile);
                    $filename = $file2->getFilename();
                    foreach ($this->config['images']['resize'] as $key => $value) {
                        $image_info = $this->imageSizeName($path,$filename,$value);
                        $filename_new = $image_info['name'];
                        $fullpaththumb_name = $this->getFullPath().'/'.$this->config['folder_thumb'].$path.$filename_new;
                        $file->remove($fullpaththumb_name);
                    }
                    // Prefer StorageService delete when available
                    if ($this->storage && $this->storage->fileExists($fullpath.$namefile)) {
                        $this->storage->delete($fullpath.$namefile);
                    } else {
                        $file->remove($fullpath.$namefile);
                    }
                    $result = array("query"=>"BE_DELETE_DELETED","params"=>array());
                    $this->setInfo(array("msg"=>$result));
                }else{
                    if( $this->config['debug'] ) $this->_log('$fullpath.$namefile - '.$fullpath.$namefile);
                    $result = array("query"=>"BE_DELETE_NOT_EXIED","params"=>array());
                    $this->setInfo(array("msg"=>$result, "status"=> 0));
                }
            }else{
                if( $this->config['debug'] ) $this->_log('$fullpath.$namefile - '.$fullpath.$namefile);
                $result = array("query"=>"BE_DELETE_NOT_EXIED","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=> 0));
            }
        }elseif(is_array($namefiles)){
            if(count($namefiles)>0){
                $fullpath = $this->getFullPath().$path;
                $data = array();
                foreach ($namefiles as $key => $namefile) {
                    $file = new Filesystem;
                    if($this->validNameFile($namefile,false) && $file->exists($fullpath.$namefile)){
                        if( $this->config['debug'] ) $this->_log('$fullpath.$namefile - '.$fullpath.$namefile);
                        if(is_dir($fullpath.$namefile)){
                            $file->remove($fullpath.$namefile);
                            $file->remove($this->getFullPath().'/'.$this->config['folder_thumb'].$path.$namefile);
                                    if ($this->storage && $this->storage->fileExists($fullpath.$namefile)) {
                                        $this->storage->delete($fullpath.$namefile);
                                    } else {
                                        $file->remove($fullpath.$namefile);
                                    }
                                    $data[] = array("status"=>1,"namefile"=>$namefile,"query"=>"BE_DELETE_DELETED","params"=>array());
                        }elseif($this->validNameFile($namefile) && is_file($fullpath.$namefile)){
                            $file2 = new \SplFileInfo($fullpath.$namefile);
                            $filename = $file2->getFilename();
                            foreach ($this->config['images']['resize'] as $key => $value) {
                                $image_info = $this->imageSizeName($path,$filename,$value);
                                $filename_new = $image_info['name'];
                                $fullpaththumb_name = $this->getFullPath().'/'.$this->config['folder_thumb'].$path.$filename_new;
                                $file->remove($fullpaththumb_name);
                            }
                            $file->remove($fullpath.$namefile);
                            $data[] = array("status"=>1,"namefile"=>$namefile,"query"=>"BE_DELETE_DELETED","params"=>array());
                        }else{
                            $data[] = array("status"=>0,"namefile"=>$namefile,"query"=>"BE_DELETE_NOT_EXIED","params"=>array());
                        }
                    }else{
                        $data[] = array("status"=>0,"namefile"=>$namefile,"query"=>"BE_DELETE_NOT_EXIED","params"=>array());
                    }
                }
                $this->setInfo(array("data"=>$data));
            }else{
                $result = array("query"=>"BE_DELETE_NOT_EXIED","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=> 0));
            }
        }
    }

    /**
     * Rename a file
     * @param string $nameold
     * @param string $namenew
     * @param string $path
     * @return void
     */
    public function rename($nameold,$namenew,$path){
        if ($this->repository && method_exists($this->repository, 'rename')) {
            return $this->repository->rename($nameold, $namenew, $path);
        }

        if($this->validNameFile($nameold,false) && $this->validNameFile($namenew,false)){
            $fullpath = $this->getFullPath().$path;
            $nameold = $this->clearNameFile($nameold);
            $namenew = $this->clearNameFile($namenew);

            $file = new Filesystem;
            if($file->exists($fullpath.$nameold)){
                if( $this->config['debug'] ) $this->_log('$fullpath.$nameold - '.$fullpath.$nameold);
                if(is_dir($fullpath.$nameold)){
                    $namenew = $this->sanitizeNameFolder($namenew);
                    if($file->exists($fullpath.$namenew)==false){
                        $file->rename($fullpath.$nameold,$fullpath.$namenew);
                        $result = array("query"=>"BE_RENAME_MODIFIED","params"=>array());
                        $this->setInfo(array("msg"=>$result,"data"=>array("namefile" => $namenew )));

                    }else{
                        $result = array("query"=>"BE_RENAME_EXISTED","params"=>array());
                        $this->setInfo(array("msg"=>$result,"status"=>0));
                    }
                }elseif(is_file($fullpath.$nameold)){
                    if($this->validExt($nameold)){
                        $extold = $this->getExtension($nameold);
                        $namenew = $namenew.'.'.$extold;
                        if($file->exists($fullpath.$namenew)==false){
                            $file2 = new \SplFileInfo($fullpath.$nameold);
                            if(in_array($file2->getExtension(), $this->config['images']['images_ext'])){
                                $filename = $file2->getFilename();
                                foreach ($this->config['images']['resize'] as $key => $value) {
                                    $image_info = $this->imageSizeName($path,$filename,$value);
                                    $filename_old = $image_info['name'];
                                    $fullpaththumb_name = $this->getFullPath().'/'.$this->config['folder_thumb'].$path.$filename_old;
                                    $file->remove($fullpaththumb_name);
                                }
                            }
                            $file->rename($fullpath.$nameold,$fullpath.$namenew);
                            $file3 = new \SplFileInfo($fullpath.$namenew);
                            $this->createThumb($file3,$path);
                            $result = array("query"=>"BE_RENAME_MODIFIED","params"=>array());
                            $this->setInfo(array("msg"=>$result,"data"=>array("namefile" => $namenew )));
                        }else{
                            $result = array("query"=>"BE_RENAME_EXISTED","params"=>array());
                            $this->setInfo(array("msg"=>$result,"status"=>0));
                        }
                    }else{
                        $result = array("query"=>"BE_RENAME_FILENAME_NOT_VALID","params"=>array());
                        $this->setInfo(array("msg"=>$result, "status"=>0));
                    }
                }
            }else{
                $result = array("query"=>"BE_RENAME_NOT_EXISTS","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=>0));
            }
        }else{
           $result = array("query"=>"BE_RENAME_FILENAME_NOT_VALID","params"=>array());
           $this->setInfo(array("msg"=>$result, "status"=>0));
       }
   }

    /**
     * Move a file or directory
     * @param  string $nameold file name
     * @param  string $pathnew destination directory path
     * @param  string $path    source directory path
     * @return void
     */
    public function move($nameold,$pathnew,$path){
        if ($this->repository && method_exists($this->repository, 'move')) {
            return $this->repository->move($nameold, $pathnew, $path);
        }

        if($this->validNameFile($nameold,false) && preg_match_all("#^/{1}$|^/[a-z0-9A-Z]{1}([a-z0-9A-Z-_/])*(/|[a-z0-9A-Z-_]{1,})+$#",$pathnew) > 0){
            $nameold = $this->clearNameFile($nameold);
            $fullpath = $this->sanitizeNameFolder($this->getFullPath().$path,true);
            $fullpathnew = $this->sanitizeNameFolder($this->getFullPath().$pathnew,true);
            if( $this->config['debug'] ) $this->_log('$fullpath - '.$fullpath);
            if( $this->config['debug'] ) $this->_log('$fullpathnew - '.$fullpathnew);
            if( $this->config['debug'] ) $this->_log('$fullpath/$nameold - '.$fullpath.'/'.$nameold);
            if( $this->config['debug'] ) $this->_log('$fullpathnew/$nameold - '.$fullpathnew.'/'.$nameold);

            if($fullpath == $fullpathnew || $fullpath.'/'.$nameold==$fullpathnew.'/'.$nameold){
                if( $this->config['debug'] ) $this->_log('$path == $pathnew Equals');
                $result = array("query"=>"BE_MOVE_PATH_NOT_VALID","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=>0));
                return;
            }elseif(strpos($fullpathnew.'/'.$nameold,$fullpath.'/'.$nameold.'/')!==false ){
                if( $this->config['debug'] ) $this->_log('$pathnew internal of source - '.$pathnew);
                $result = array("query"=>"BE_MOVE_PATH_NOT_VALID","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=>0));
                return;
            }elseif(strpos($fullpath.'/'.$nameold,$fullpathnew.'/'.$nameold.'/')!==false ){
                if( $this->config['debug'] ) $this->_log('$pathnew not moved in same folder - '.$pathnew);
                $result = array("query"=>"BE_MOVE_PATH_NOT_VALID","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=>0));
                return;
            }
            $file = new Filesystem;
            if($file->exists($fullpath.'/'.$nameold)){
                if(is_dir($fullpath.'/'.$nameold)){
                    if($file->exists($fullpathnew.'/'.$nameold)===false){
                        if($file->exists($fullpathnew)==false){
                            $file->mkdir($fullpathnew);
                        }
                        $file->rename($fullpath.'/'.$nameold,$fullpathnew.'/'.$nameold);
                        $result = array("query"=>"BE_MOVE_MOVED","params"=>array());
                        $fullpathnew_result = $this->sanitizeNameFolder($pathnew.'/'.$nameold,true);
                        $this->setInfo(array("msg"=>$result,"data"=>array("namefile" => $fullpathnew_result)));
                    }else{
                        $result = array("query"=>"BE_MOVE_FOLDER_EXISTED","params"=>array());
                        $this->setInfo(array("msg"=>$result, "status"=>0));
                    }

                }elseif(is_file($fullpath.'/'.$nameold)){
                    if($this->validExt($nameold)){
                        if($file->exists($fullpathnew.'/'.$nameold)===false){
                            if($file->exists($fullpathnew)==false){
                                $file->mkdir($fullpathnew);
                            }
                            $file2 = new \SplFileInfo($fullpath.'/'.$nameold);
                            if(in_array($file2->getExtension(), $this->config['images']['images_ext'])){
                                $filename = $file2->getFilename();
                                foreach ($this->config['images']['resize'] as $key => $value) {
                                    $image_info = $this->imageSizeName($path,$filename,$value);
                                    $filename_old = $image_info['name'];
                                    $fullpaththumb_name = $this->getFullPath().'/'.$this->config['folder_thumb'].$path.$filename_old;
                                    $file->remove($fullpaththumb_name);
                                }
                            }
                            $file->rename($fullpath.'/'.$nameold,$fullpathnew.'/'.$nameold);
                            $result = array("query"=>"BE_MOVE_MOVED","params"=>array());
                            $fullpathnew_result = $this->sanitizeNameFolder($pathnew.'/'.$nameold,true);
                            $this->setInfo(array("msg"=>$result,"data"=>array("namefile" => $fullpathnew_result)));
                        }else{
                            $result = array("query"=>"BE_MOVE_EXISTED","params"=>array());
                            $this->setInfo(array("msg"=>$result, "status"=>0));
                        }
                    }else{
                        $result = array("query"=>"BE_MOVE_FILENAME_NOT_VALID","params"=>array());
                        $this->setInfo(array("msg"=>$result, "status"=>0));
                    }
                }
            }else{
                $result = array("query"=>"BE_MOVE_NOT_EXISTS","params"=>array());
                $this->setInfo(array("msg"=>$result, "status"=>0));
            }
        }else{
            $result = array("query"=>"BE_MOVE_FILENAME_NOT_VALID","params"=>array());
            $this->setInfo(array("msg"=>$result, "status"=>0));
        }
    }

    /**
     * Download a file
     * @param  string $name file name
     * @param  string $path relative path
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
