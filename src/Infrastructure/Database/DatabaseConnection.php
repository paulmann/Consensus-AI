<?php
declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;

/**
 * DatabaseConnection class manages the PDO connection to MySQL.
 * Implements a singleton pattern for efficient connection reuse.
 */
final class DatabaseConnection
{
    private static ?self $instance = null;
    private readonly PDO $pdo;
    private bool $connected = false;

    private function __construct(
        private readonly string $host,
        private readonly string $dbName,
        private readonly string $username,
        private readonly string $password,
        private readonly int $port = 3306,
        private readonly string $charset = 'utf8mb4'
    ) {
        $this->pdo = $this->createConnection();
        $this->connected = true;
    }

    /**
     * Get the singleton instance of the database connection.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $dbName = $_ENV['DB_NAME'] ?? 'consensus_ai';
            $username = $_ENV['DB_USER'] ?? 'root';
            $password = $_ENV['DB_PASS'] ?? '';
            $port = (int) ($_ENV['DB_PORT'] ?? 3306);
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

            self::$instance = new self($host, $dbName, $username, $password, $port, $charset);
        }

        return self::$instance;
    }

    /**
     * Create the PDO connection.
     */
    private function createConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;port=%d;charset=%s',
            $this->host,
            $this->dbName,
            $this->port,
            $this->charset
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ];

        try {
            return new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new PDOException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the PDO instance.
     */
    public function getPdo(): PDO
    {
        if (!$this->connected) {
            throw new PDOException('Database connection is not established.');
        }
        return $this->pdo;
    }

    /**
     * Check if the connection is active.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the singleton instance.
     */
    public function __wakeup(): void
    {
        throw new PDOException('Cannot unserialize a singleton.');
    }
}
