<?php
/**
 * PHPUnit Bootstrap File
 * Sets up autoloader and test environment
 */

// Get composer autoloader
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("Composer autoloader not found at $autoloadPath\nRun: composer install\n");
}
require_once $autoloadPath;

// Define test temp directory
define('TEST_FIXTURES_DIR', __DIR__ . '/fixtures');
define('TEST_TEMP_DIR', sys_get_temp_dir() . '/filemanager-tests-' . time());

// Create temp directory
if (!is_dir(TEST_TEMP_DIR)) {
    @mkdir(TEST_TEMP_DIR, 0777, true);
}

// Register cleanup on shutdown
register_shutdown_function(function() {
    // Clean up temp test files
    if (is_dir(TEST_TEMP_DIR)) {
        array_map('unlink', glob(TEST_TEMP_DIR . '/*'));
        @rmdir(TEST_TEMP_DIR);
    }
});
