<?php

/**
 * Configuración de Base de Datos PostgreSQL
 * Sistema de Screening de Contratación
 */

// Cargar variables de entorno
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar .env si existe
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

return [
    'default' => 'pgsql',

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '5432',
            'database' => $_ENV['DB_DATABASE'] ?? 'screening_contratacion',
            'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $_ENV['DB_SCHEMA'] ?? 'public',
            'sslmode' => $_ENV['DB_SSL_MODE'] ?? 'prefer',

            // Configuraciones adicionales para PostgreSQL
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => (int)($_ENV['DB_TIMEOUT'] ?? 30),
            ],

            // Pool de conexiones
            'pool' => [
                'max_connections' => (int)($_ENV['DB_MAX_CONNECTIONS'] ?? 20),
                'idle_timeout' => 300, // 5 minutos
                'connect_timeout' => 10,
            ],
        ],

        // Configuración para testing
        'testing' => [
            'driver' => 'pgsql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '5432',
            'database' => $_ENV['TEST_DATABASE'] ?? 'screening_contratacion_test',
            'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    // Configuración de migraciones
    'migrations' => [
        'table' => 'migrations',
        'path' => __DIR__ . '/../../database/migrations',
    ],

    // Configuración de logging de queries
    'logging' => [
        'enabled' => filter_var($_ENV['SQL_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'log_file' => $_ENV['DATABASE_LOG_FILE'] ?? 'logs/database.log',
        'slow_query_threshold' => 1000, // milisegundos
    ],

    // Configuración de cache de queries
    'cache' => [
        'enabled' => filter_var($_ENV['CACHE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'ttl' => (int)($_ENV['CACHE_TTL_SECONDS'] ?? 3600),
        'prefix' => 'screening_db_',
    ],

    // Configuración específica para Levenshtein y similitud
    'similarity' => [
        'default_threshold' => (float)($_ENV['DEFAULT_SIMILARITY_THRESHOLD'] ?? 70.0),
        'min_threshold' => (float)($_ENV['MIN_SIMILARITY_THRESHOLD'] ?? 50.0),
        'max_threshold' => (float)($_ENV['MAX_SIMILARITY_THRESHOLD'] ?? 100.0),
        'search_limit' => 50, // Máximo de resultados por búsqueda de similitud
    ],

    // Configuración de backup
    'backup' => [
        'enabled' => filter_var($_ENV['AUTO_BACKUP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'path' => __DIR__ . '/../../database/backups',
        'schedule' => $_ENV['BACKUP_SCHEDULE'] ?? '0 2 * * *',
        'retention_days' => (int)($_ENV['BACKUP_RETENTION_DAYS'] ?? 30),
        'compress' => true,
    ],

    // Configuración de health check
    'health_check' => [
        'enabled' => filter_var($_ENV['HEALTH_CHECK_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'interval_seconds' => 60,
        'timeout_seconds' => 5,
    ],
];
