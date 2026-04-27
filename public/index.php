<?php
/**
 * Consensus-AI - Public Entry Point
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 2.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

use App\Http\Router;
use App\Http\Controller\CouncilController;
use App\Http\Controller\SkillController;
use App\Infrastructure\BothubChat\BothubClient;
use App\Infrastructure\Vector\MySQLVectorStore;
use App\Domain\Council\SkillRepositoryInterface;
use App\Infrastructure\Council\SkillRepositoryPdo;
use App\Application\Council\SkillSearch;
use App\Application\Council\SkillAutoCreator;
use App\Application\Council\SkillRouter;
use App\Application\Council\CouncilEngine;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../vendor/autoload.php';

// -----------------------------------------------------------------------------
// Environment loading (simple .env parser, no external dependency)
// -----------------------------------------------------------------------------

$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $_ENV[$key] = $value;
        if (!array_key_exists($key, $_SERVER)) {
            $_SERVER[$key] = $value;
        }
    }
}

// -----------------------------------------------------------------------------
// Error reporting & logging
// -----------------------------------------------------------------------------

$debug = ($_ENV['APP_DEBUG'] ?? '0') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0777, true);
}
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Sessions (optional, but keep for future auth)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// Minimal manual dependency wiring (no external DI container)
// -----------------------------------------------------------------------------

try {
    // Database connection (PDO)
    $dsn      = $_ENV['DB_DSN']      ?? 'mysql:host=127.0.0.1;dbname=consensus_ai;charset=utf8mb4';
    $dbUser   = $_ENV['DB_USER']     ?? 'root';
    $dbPass   = $_ENV['DB_PASSWORD'] ?? '';
    $pdo      = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Logger (can be replaced with Monolog later)
    $logger = new NullLogger();

    // Core infrastructure services
    $vectorStore = new MySQLVectorStore($pdo, $logger);
    $bothubClient = new BothubClient(
        baseUrl: $_ENV['BOTHUB_BASE_URL'] ?? 'https://api.bothub.chat/v1',
        apiKey: $_ENV['BOTHUB_API_KEY']   ?? '',
        logger: $logger
    );

    // Domain repositories
    $skillRepository = new SkillRepositoryPdo($pdo, $logger);

    // Application services
    $skillSearch      = new SkillSearch($vectorStore, $bothubClient, $skillRepository, $logger);
    $skillAutoCreator = new SkillAutoCreator($bothubClient, $skillRepository, $skillSearch, $logger);
    $skillRouter      = new SkillRouter($skillRepository, $skillSearch, $skillAutoCreator, $logger);
    $councilEngine    = new CouncilEngine($pdo, $skillRouter, $bothubClient, $logger);

    // Seed first Skill if none exist (bootstraps default council behaviour)
    if ($skillRepository->count() === 0) {
        $seedSkill = $skillAutoCreator->seedDefaultConsensusSkill();
        $logger->info('Seeded initial consensus SKILL', ['skill_id' => $seedSkill->getId()]);
    }

    // HTTP controllers
    $councilController = new CouncilController($councilEngine, $skillRouter, $logger);
    $skillController   = new SkillController($skillRepository, $skillSearch, $skillAutoCreator, $logger);

    // Router and route definitions
    $router = new Router();

    // Council endpoints
    $router->post('/api/council/run', [$councilController, 'run']);
    $router->get('/api/council/session/{id}', [$councilController, 'getSession']);
    $router->get('/api/council/session/{id}/graph', [$councilController, 'getSessionGraph']);
    $router->get('/api/council/session/{id}/step/{stepId}', [$councilController, 'getSessionStep']);

    // Skill endpoints
    $router->get('/api/skills', [$skillController, 'index']);
    $router->get('/api/skills/{id}', [$skillController, 'show']);
    $router->post('/api/skills', [$skillController, 'store']);
    $router->delete('/api/skills/{id}', [$skillController, 'destroy']);
    $router->get('/api/skills/search', [$skillController, 'search']);

    // CORS preflight handlers
    $router->options('/api/{any}', static function (): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        http_response_code(204);
    });

    // Dispatch current HTTP request
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';

    $router->dispatch($method, $uri);
} catch (Throwable $e) {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if ($debug) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => true,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
        echo '<h1>Internal Server Error</h1>';
        echo '<p>Something went wrong. Please try again later.</p>';
        echo '</body></html>';
    }
}
