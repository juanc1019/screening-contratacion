<?php

namespace ScreeningApp\Tests;

use PHPUnit\Framework\TestCase;
use ScreeningApp\Database;
use Dotenv\Dotenv;
use PDO;

class DatabaseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load environment variables from .env file
        // Adjust the path to the .env file as necessary relative to the vendor/bin/phpunit execution
        $dotenvPath = __DIR__ . '/../../'; // Assuming phpunit is run from vendor/bin
        if (file_exists($dotenvPath . '.env')) {
            $dotenv = Dotenv::createImmutable($dotenvPath);
            $dotenv->load();
        } elseif (file_exists($dotenvPath . '.env.example')) {
            // Fallback to .env.example if .env is not found, for some environments
            $dotenv = Dotenv::createImmutable($dotenvPath, '.env.example');
            $dotenv->load();
        }
        // If neither is found, the Database class will rely on actual environment variables or its defaults
    }

    public function testDatabaseConnection(): void
    {
        // Ensure database configuration is loaded via environment variables
        // The Database class constructor handles loading its own config and connecting.
        $dbInstance = Database::getInstance();
        $this->assertInstanceOf(Database::class, $dbInstance, "Failed to get Database instance.");

        $connection = $dbInstance->getConnection();
        $this->assertInstanceOf(PDO::class, $connection, "Database connection is not a PDO instance.");

        // Optionally, check if the connection is actually alive
        $stmt = $connection->query("SELECT 1");
        $this->assertNotFalse($stmt, "Query failed, connection might not be fully active.");
        if ($stmt) {
            $stmt->closeCursor();
        }
    }
}
