# Filemanager Package

A modern, framework-agnostic PHP file management package with clean separation of concerns, Flysystem storage abstraction, and image resizing capabilities.

## Features

- **Framework-Agnostic** — Use with any PHP application, Composer-compatible project
- **Modular Architecture** — Independent components (StorageService, ImageService, FileRepository)
- **Flysystem Integration** — Abstract storage layer for local/remote filesystems
- **Image Resizing** — Built-in thumbnail generation via Gregwar/Image
- **Security** — Path traversal protection, file type validation, sanitization
- **Extensible** — Service injection, repository pattern for testability
- **Well-Tested** — Comprehensive PHPUnit test suite

## Minimum Requirements

- **PHP: 8.0+** (supports PHP 8.2)
- **Composer** for dependency management

## Architecture

```
src/GuillermoMartinez/Filemanager/
├── Filemanager.php           # Thin dispatcher (reads globals, delegates to repository)
├── Service/
│   ├── StorageService.php    # Flysystem wrapper, file I/O
│   ├── ImageService.php      # Image resizing via Gregwar
│   └── AuthService.php       # Token validation (extensible)
├── Repository/
│   └── FileRepository.php    # Facade for all file operations
└── Exception/
    └── FilemanagerException.php
```

### Key Components

#### **Filemanager** (Dispatcher)
Minimal request dispatcher that routes actions to the repository. **No authentication or framework coupling** — authorization is handled by the consuming application (e.g., CodeIgniter filter).

```php
$fm = new \GuillermoMartinez\Filemanager\Filemanager($options);
$fm->run(); // Reads $_POST/$_GET, returns JSON response
```

#### **StorageService**
Flysystem-backed file storage abstraction with path normalization.

```php
$storage = new StorageService(['root' => '/path/to/files']);
$storage->write('/file.txt', 'content');
$contents = $storage->read('/file.txt');
$list = $storage->listContents('/');
$storage->delete('/file.txt');
```

#### **ImageService**
Image resizing and thumbnail generation via Gregwar\Image.

```php
$image = new ImageService(['quality' => 90]);
$image->resize(
    '/path/to/original.jpg',
    '/path/to/thumb.jpg',
    ['width' => 120, 'height' => 90],
    [120, 90, true, true], // [width, height, crop_w, crop_h]
    90 // quality
);
```

#### **FileRepository**
Facade for file operations: listing, upload, delete, rename, move.

```php
$repo = new FileRepository($config, $storage, $imageService);
$files = $repo->getAllFiles('/folder/');
$repo->newFolder('new_folder', '/');
$repo->delete('file.txt', '/folder/');
$repo->rename('old.txt', 'new.txt', '/');
$repo->move('file.txt', '/dest/', '/source/');
```

## Installation

```bash
composer require guillermomartinez/filemanager-php
```

## Basic Usage

```php
use GuillermoMartinez\Filemanager\Filemanager;

$config = [
    'doc_root' => '/var/www/uploads',
    'source' => 'files',
    'url' => 'https://example.com/uploads/',
    'debug' => false,
    'ext' => ['jpg', 'png', 'pdf', 'txt', 'docx'],
    'images' => [
        'images_ext' => ['jpg', 'png', 'gif'],
        'resize' => [
            'thumb' => [120, 90, true, true],
            'medium' => [400, 300, false, null],
        ],
        'quality' => 90,
    ],
    'upload' => [
        'number' => 5,
        'overwrite' => false,
        'size_max' => 50,  // MB
    ],
    'storage' => [
        'root' => '/var/www/uploads'
    ],
];

$fm = new Filemanager($config);
$fm->run();  // Reads globals, processes request, returns JSON
```

## API Actions

The package supports the following actions (sent via POST/GET):

### GET
- **download** — Download a file
  - Params: `action=download&path=/folder/&name=file.txt`
  - Returns: File stream with content-disposition attachment

### POST
- **getfolder** — List files/folders
  - Params: `action=getfolder&path=/folder/`
  - Returns: JSON array of file/folder metadata
  
- **uploadfile** — Upload files (multipart/form-data)
  - Params: `action=uploadfile&path=/folder/&file=<FILES>`
  - Returns: JSON array of uploaded filenames
  
- **newfolder** — Create directory
  - Params: `action=newfolder&path=/folder/&name=new_folder`
  - Returns: JSON with `{path, namefile}`
  
- **renamefile** — Rename file/folder
  - Params: `action=renamefile&path=/folder/&nameold=old.txt&name=new.txt`
  
- **movefile** — Move file/folder
  - Params: `action=movefile&path=/source/&nameold=file.txt&name=/dest/`
  
- **deletefile** — Delete file/folder
  - Params: `action=deletefile&path=/folder/&name=file.txt`

## Integration with CodeIgniter 4

### 1. Create Token Validation Filter

**`app/Filters/FilemanagerFilter.php`:**
```php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class FilemanagerFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Extract token from header or params
        $token = $request->getHeaderLine('X-Filemanager-Token')
            ?: $request->getPost('token')
            ?: $request->getGet('token');

        // Get allowed tokens from environment
        $tokensEnv = getenv('FILEMANAGER_TOKENS') ?: '';
        $allowedTokens = array_filter(array_map('trim', 
            explode(',', $tokensEnv)));

        // Validate token
        if (!$token || !in_array($token, $allowedTokens, true)) {
            return service('response')
                ->setJSON(['status' => 0, 'msg' => ['query' => 'BE_AUTH_NOT_ALLOWED']])
                ->setStatusCode(401);
        }

        // Token valid; store for later use
        $request->filemanagerToken = $token;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        //
    }
}
```

### 2. Register Filter

**`app/Config/Filters.php`:**
```php
public $aliases = [
    'csrf'          => CSRF::class,
    'filemanager'   => \App\Filters\FilemanagerFilter::class,
];
```

### 3. Setup Routes

**`app/Config/Routes.php`:**
```php
$routes->match(['get','post'], '/rest/v1/filemanager', 
    'Filemanager\\Filemanager::api')
    ->filter('filemanager');
$routes->match(['get','post'], '/rest/v1/filemanager/(:any)', 
    'Filemanager\\Filemanager::api')
    ->filter('filemanager');
```

### 4. Create Controller

**`app/Controllers/Filemanager/Filemanager.php`:**
```php
namespace App\Controllers\Filemanager;

use App\Controllers\ApiController;
use GuillermoMartinez\Filemanager\Filemanager as FM;

class Filemanager extends ApiController
{
    public function api()
    {
        // Filter has already validated token (before this runs)
        $options = [
            'doc_root'  => ROOTPATH . 'public/assets/cms/uploads/',
            'url'       => getenv('FILEMANAGER_PUBLIC_URL') ?: 'http://localhost:8080/uploads/',
            'debug'     => (bool) (getenv('FILEMANAGER_DEBUG') ?: false),
            'debugfile' => WRITEPATH . 'logs/filemanager.log',
            'storage'   => ['root' => ROOTPATH . 'public/assets/cms/uploads/'],
            'images'    => [
                'resize'  => [
                    'medium' => [340, 180, true, true],
                    'large'  => [1024, 768, true, true],
                ],
                'quality' => 90,
            ],
        ];

        (new FM($options))->run();
        return;
    }
}
```

### 5. Environment Configuration

**`.env`:**
```env
FILEMANAGER_TOKENS=token1,token2,token3
FILEMANAGER_DEBUG=false
FILEMANAGER_PUBLIC_URL=https://example.com/uploads/
```

## Testing

### Install Dev Dependencies

```bash
cd src/packages/filemanager-php
composer install
```

### Run All Tests

```bash
./vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
./vendor/bin/phpunit tests/Unit/Service/StorageServiceTest.php
./vendor/bin/phpunit tests/Unit/Repository/FileRepositoryTest.php
./vendor/bin/phpunit tests/Unit/FilemanagerTest.php
```

### View Coverage Report

```bash
./vendor/bin/phpunit --coverage-html coverage/
open coverage/index.html
```

## Security Best Practices

1. **Token Validation** — Always enforce authentication at framework layer (e.g., filter)
2. **File Extension Whitelist** — Only allow intended file types in config
3. **Path Traversal Prevention** — Package validates paths; never trust user input
4. **Filename Sanitization** — Special characters are cleaned; spaces converted to hyphens
5. **File Permissions** — Set umask appropriately: `644` for files, `755` for directories
6. **CORS Headers** — Add CORS restrictions if exposing API to frontend
7. **Rate Limiting** — Implement rate limiting on file upload endpoints
8. **Logging** — Enable debug mode in development; log to secure location in production
9. **File Size Limits** — Enforce reasonable max upload sizes
10. **Virus Scanning** — Consider integrating ClamAV or similar for production uploads

## Configuration Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `doc_root` | string | `$_SERVER['DOCUMENT_ROOT']` | Base filesystem path |
| `source` | string | `userfiles` | Subdirectory name under doc_root |
| `url` | string | `/` | Public URL path for serving files |
| `debug` | bool | `false` | Log debugging information |
| `debugfile` | string | `log/filemanager.log` | Debug log file path |
| `ext` | array | `['jpg', 'pdf', ...]` | Allowed file extensions (uppercase ignored) |
| `images.images_ext` | array | `['jpg', 'png', 'gif']` | Image file types for thumbnail generation |
| `images.resize` | array | `[...]` | Thumbnail profiles: `['name' => [w, h, crop_w, crop_h]]` |
| `images.quality` | int | `100` | JPEG/PNG quality (1-100) |
| `upload.number` | int | `5` | Max files per request |
| `upload.overwrite` | bool | `false` | Overwrite existing files |
| `upload.size_max` | int | `10` | Max filesize in MB |
| `folder_thumb` | string | `_thumbs` | Thumbnail directory name |
| `storage.root` | string | `doc_root/source` | Flysystem root path |

## UI Integration

Use with the official UI: [guillermomartinez/filemanager-ui](https://github.com/guillermomartinez/filemanager-ui)

Configure UI backend endpoint:
```javascript
backendUrl: 'https://example.com/rest/v1/filemanager',
token: 'your-token-here'
```

## License

MIT License

## Support

For issues, questions, or contributions, please refer to the GitHub repository.


## Demo
http://php-filemanager.rhcloud.com/

![demo2](https://cloud.githubusercontent.com/assets/5642429/8630887/aec46114-2731-11e5-9a7b-907127d77891.jpg)
![demo1](https://cloud.githubusercontent.com/assets/5642429/8630885/ae7e7122-2731-11e5-88bb-b8fd2f5ae9a5.jpg)
![demo3](https://cloud.githubusercontent.com/assets/5642429/8630886/aeaa1b7e-2731-11e5-9097-cafeefba1aea.jpg)
