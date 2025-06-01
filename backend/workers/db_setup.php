<?php

/**
 * Script de verificación y setup de base de datos
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar variables de entorno
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_PORT'] ?? '5432',
        $_ENV['DB_DATABASE'] ?? 'screening_contratacion'
    );

    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USERNAME'] ?? 'postgres',
        $_ENV['DB_PASSWORD'] ?? 'ptf1019',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "✅ Conexión a PostgreSQL exitosa\n";

    // Verificar extensiones
    $extensions = ['uuid-ossp', 'fuzzystrmatch', 'pg_trgm'];
    foreach ($extensions as $ext) {
        try {
            $pdo->exec("CREATE EXTENSION IF NOT EXISTS \"{$ext}\"");
            echo "✅ Extensión {$ext} habilitada\n";
        } catch (Exception $e) {
            echo "⚠️  No se pudo habilitar {$ext}: " . $e->getMessage() . "\n";
        }
    }

    echo "🎉 Setup de base de datos completado\n";
} catch (Exception $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    echo "💡 Verifica las credenciales en .env\n";
    exit(1);
}
