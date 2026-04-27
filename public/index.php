<?php
declare(strict_types=1);

/**
 * Consensus-AI - Public Entry Point
 * 
 * This is the main entry point for the application.
 * All requests are routed through this file.
 */

use App\Infrastructure\Database\DatabaseConnection;
use App\Infrastructure\Http\Router;

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', $_ENV['APP_DEBUG'] ?? '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Session start
session_start();

try {
    // Initialize database connection
    $dbConnection = DatabaseConnection::getInstance();
    
    // Initialize router
    $router = new Router($dbConnection);
    
    // Dispatch the request
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    
} catch (Throwable $e) {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
        echo '<h1>Internal Server Error</h1>';
        echo '<p>Something went wrong. Please try again later.</p>';
        echo '</body></html>';
    }
}
