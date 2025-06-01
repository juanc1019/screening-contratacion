<?php

namespace ScreeningApp;

use PDO;
use PDOException;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Clase Database - Manejo de conexiones y operaciones PostgreSQL
 * Optimizada para búsquedas de similitud con Levenshtein
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;
    /** @var array<string, mixed> */
    private array $config;
    private Logger $logger;
    private int $queryCount = 0;
    private float $totalQueryTime = 0.0;

    /**
     * Destructor - cierra la conexión automáticamente
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Previene la clonación del objeto Singleton
     */
    private function __clone()
    {
    }

    /**
     * Previene la deserialización del objeto Singleton
     */
    public function __wakeup()
    {
        throw new Exception("No se puede deserializar un singleton.");
    }

    /**
     * Constructor privado para patrón Singleton
     */
    private function __construct()
    {
        $this->config = require __DIR__ . '/../config/database.php';
        $this->setupLogger();
        $this->connect();
    }

    /**
     * Obtiene la instancia única de Database
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Configura el logger para la base de datos
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('database');
        $logFile = $this->config['logging']['log_file'] ?? 'logs/database.log';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }

    /**
     * Establece la conexión a PostgreSQL
     */
    private function connect(): void
    {
        try {
            $dbConfig = $this->config['connections']['pgsql'];

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;options=--search_path=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['search_path']
            );

            $this->connection = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                $dbConfig['options']
            );

            // Configurar timezone y encoding
            $this->connection->exec("SET timezone = 'America/Mexico_City'");
            $this->connection->exec("SET client_encoding = 'UTF8'");

            // Habilitar extensiones si no están activas
            $this->enableExtensions();

            $this->logger->info('Conexión a PostgreSQL establecida exitosamente');
        } catch (PDOException $e) {
            $this->logger->error('Error conectando a PostgreSQL: ' . $e->getMessage());
            throw new Exception('Error de conexión a base de datos: ' . $e->getMessage());
        }
    }

    /**
     * Habilita las extensiones necesarias de PostgreSQL
     */
    private function enableExtensions(): void
    {
        if ($this->connection === null) {
            $this->connect();
            if ($this->connection === null) { // Still null after trying to connect
                $this->logger->error("No hay conexión a BDD para habilitar extensiones.");
                throw new Exception("No hay conexión a BDD para habilitar extensiones.");
            }
        }

        $extensions = [
            'uuid-ossp',
            'fuzzystrmatch',  // Para Levenshtein
            'pg_trgm'         // Para búsquedas de texto optimizadas
        ];

        foreach ($extensions as $extension) {
            try {
                $this->connection->exec("CREATE EXTENSION IF NOT EXISTS \"{$extension}\"");
            } catch (PDOException $e) {
                $this->logger->warning("No se pudo habilitar extensión {$extension}: " . $e->getMessage());
            }
        }
    }

    /**
     * Obtiene la conexión PDO
     * @throws Exception Si la conexión no se puede establecer
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
            if ($this->connection === null) { // Check again after trying to connect
                 $this->logger->error("No se pudo establecer la conexión a la base de datos.");
                 throw new Exception("No se pudo establecer la conexión a la base de datos.");
            }
        }
        return $this->connection;
    }

    /**
     * Ejecuta una consulta preparada con logging
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return \PDOStatement
     * @throws Exception
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $startTime = microtime(true);

        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);

            $executionTime = (microtime(true) - $startTime) * 1000; // en milisegundos
            $this->queryCount++;
            $this->totalQueryTime += $executionTime;

            // Log de queries lentas
            if ($executionTime > ($this->config['logging']['slow_query_threshold'] ?? 1000)) {
                $this->logger->warning("Query lenta detectada", [
                    'sql' => $sql,
                    'params' => $params,
                    'execution_time_ms' => $executionTime
                ]);
            }

            // Log debug si está habilitado
            if ($this->config['logging']['enabled'] ?? false) {
                $this->logger->debug("Query ejecutada", [
                    'sql' => $sql,
                    'params' => $params,
                    'execution_time_ms' => $executionTime
                ]);
            }

            return $stmt;
        } catch (PDOException $e) {
            $this->logger->error('Error ejecutando query', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Error en consulta SQL: ' . $e->getMessage());
        }
    }

    /**
     * Busca registros por similitud usando Levenshtein
     * @return array<int, array<string, mixed>>
     */
    public function searchBySimilarity(
        string $searchName,
        string $searchId = null,
        float $minSimilarity = 70.0,
        int $limit = 50
    ): array {
        $sql = "SELECT * FROM search_local_similarity($1, $2, $3, $4)";
        /** @var array<int, mixed> $params */
        $params = [$searchName, $searchId, $minSimilarity, $limit];

        $stmt = $this->query($sql, $params);
        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results;
    }

    /**
     * Calcula similitud entre dos textos
     */
    public function calculateSimilarity(string $text1, string $text2): float
    {
        $sql = "SELECT calculate_similarity($1, $2) as similarity";
        $stmt = $this->query($sql, [$text1, $text2]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (float)($result['similarity'] ?? 0.0);
    }

    /**
     * Inserta registros en lote de manera eficiente
     * @param string $table
     * @param string[] $columns
     * @param array<int, array<int, mixed>> $data
     * @return bool
     * @throws Exception
     */
    public function bulkInsert(string $table, array $columns, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $connection = $this->getConnection();
        $connection->beginTransaction();

        try {
            $placeholders = '(' . str_repeat('?,', count($columns) - 1) . '?)';
            $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES {$placeholders}";

            $stmt = $connection->prepare($sql);

            foreach ($data as $row) {
                $stmt->execute($row);
            }

            $connection->commit();

            $this->logger->info("Bulk insert exitoso", [
                'table' => $table,
                'rows_inserted' => count($data)
            ]);

            return true;
        } catch (PDOException $e) {
            $connection->rollBack();
            $this->logger->error("Error en bulk insert", [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Error en inserción masiva: ' . $e->getMessage());
        }
    }

    /**
     * Inserta un lote de búsqueda
     * @param array<string, mixed> $batchData
     * @return string
     * @throws Exception
     */
    public function createSearchBatch(array $batchData): string
    {
        $sql = "INSERT INTO search_batches (
            batch_name, original_filename, total_records, 
            search_config, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?) RETURNING id";

        $stmt = $this->query($sql, [
            $batchData['batch_name'],
            $batchData['original_filename'] ?? null,
            $batchData['total_records'],
            json_encode($batchData['search_config']),
            'created',
            $batchData['created_by'] ?? 'system'
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['id'];
    }

    /**
     * Inserta registros de búsqueda masiva
     * @param string $batchId
     * @param array<int, array<string, mixed>> $searches
     * @return bool
     * @throws Exception
     */
    public function insertBulkSearches(string $batchId, array $searches): bool
    {
        /** @var string[] $columns */
        $columns = ['batch_id', 'identification', 'full_name', 'original_row_data'];
        /** @var array<int, array<int, mixed>> $data */
        $data = [];

        foreach ($searches as $search) {
            $data[] = [
                $batchId,
                $search['identification'],
                $search['full_name'],
                json_encode($search['original_row_data'] ?? [])
            ];
        }

        return $this->bulkInsert('bulk_searches', $columns, $data);
    }

    /**
     * Actualiza el estado de una búsqueda
     */
    public function updateSearchStatus(string $searchId, string $status, string $errorMessage = null): bool
    {
        $sql = "UPDATE bulk_searches SET 
                status = ?, 
                processed_at = CURRENT_TIMESTAMP,
                error_message = ?
                WHERE id = ?";

        $stmt = $this->query($sql, [$status, $errorMessage, $searchId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Guarda resultados de búsqueda local
     * @param string $bulkSearchId
     * @param array<int, array<string, mixed>> $results
     * @return bool
     * @throws Exception
     */
    public function saveLocalResults(string $bulkSearchId, array $results): bool
    {
        if (empty($results)) {
            return true;
        }

        /** @var string[] $columns */
        $columns = ['bulk_search_id', 'local_record_id', 'similarity_percentage', 'match_type', 'similarity_details'];
        /** @var array<int, array<int, mixed>> $data */
        $data = [];

        foreach ($results as $result) {
            $data[] = [
                $bulkSearchId,
                $result['record_id'],
                $result['similarity_percentage'],
                $result['match_type'] ?? 'similarity',
                json_encode($result['similarity_details'] ?? [])
            ];
        }

        return $this->bulkInsert('search_results', $columns, $data);
    }

    /**
     * Guarda resultados de búsqueda externa
     * @param string $bulkSearchId
     * @param array<int, array<string, mixed>> $results
     * @return bool
     * @throws Exception
     */
    public function saveExternalResults(string $bulkSearchId, array $results): bool
    {
        if (empty($results)) {
            return true;
        }

        /** @var string[] $columns */
        $columns = [
            'bulk_search_id', 'site_name', 'site_category', 'search_query',
            'has_results', 'results_count', 'results_data', 'similarity_score',
            'scraper_status', 'direct_link', 'execution_time', 'error_details'
        ];
        /** @var array<int, array<int, mixed>> $data */
        $data = [];

        foreach ($results as $result) {
            $data[] = [
                $bulkSearchId,
                $result['site_name'],
                $result['site_category'] ?? '',
                $result['search_query'] ?? '',
                $result['has_results'] ?? false,
                $result['results_count'] ?? 0,
                json_encode($result['results_data'] ?? []),
                $result['similarity_score'] ?? null,
                $result['scraper_status'] ?? 'completed',
                $result['direct_link'] ?? null,
                $result['execution_time'] ?? null,
                $result['error_details'] ?? null
            ];
        }

        return $this->bulkInsert('external_results', $columns, $data);
    }

    /**
     * Obtiene el progreso de un lote
     * @param string $batchId
     * @return array<string, mixed>
     * @throws Exception
     */
    public function getBatchProgress(string $batchId): array
    {
        $sql = "SELECT * FROM v_batch_summary WHERE id = ?";
        $stmt = $this->query($sql, [$batchId]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: [];
    }

    /**
     * Obtiene resumen de resultados de búsqueda
     * @param string|null $batchId
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getSearchResultsSummary(string $batchId = null): array
    {
        if ($batchId) {
            $sql = "SELECT * FROM v_search_results_summary WHERE batch_id = ? ORDER BY created_at DESC";
            /** @var array<int, string> $params */
            $params = [$batchId];
        } else {
            $sql = "SELECT * FROM v_search_results_summary ORDER BY created_at DESC LIMIT 100";
            $params = [];
        }

        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene estadísticas de rendimiento de sitios
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getSitePerformance(): array
    {
        $sql = "SELECT * FROM v_site_performance ORDER BY success_rate DESC";
        $stmt = $this->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea una notificación
     * @param array<string, mixed> $notification
     * @return string
     * @throws Exception
     */
    public function createNotification(array $notification): string
    {
        $sql = "INSERT INTO notifications (
            type, title, message, progress_current, progress_total,
            batch_id, is_persistent, auto_dismiss_seconds
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id";

        $stmt = $this->query($sql, [
            $notification['type'],
            $notification['title'],
            $notification['message'],
            $notification['progress_current'] ?? 0,
            $notification['progress_total'] ?? 0,
            $notification['batch_id'] ?? null,
            $notification['is_persistent'] ?? false,
            $notification['auto_dismiss_seconds'] ?? 5
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['id'];
    }

    /**
     * Obtiene notificaciones no leídas
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getUnreadNotifications(): array
    {
        $sql = "SELECT * FROM notifications WHERE is_read = false ORDER BY created_at DESC";
        $stmt = $this->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marca notificaciones como leídas
     * @param string[] $notificationIds
     * @return bool
     * @throws Exception
     */
    public function markNotificationsAsRead(array $notificationIds): bool
    {
        if (empty($notificationIds)) {
            return true;
        }

        $placeholders = str_repeat('?,', count($notificationIds) - 1) . '?';
        $sql = "UPDATE notifications SET is_read = true, read_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})";

        $stmt = $this->query($sql, $notificationIds);
        return $stmt->rowCount() > 0;
    }

    /**
     * Registra un log del sistema
     * @param string $level
     * @param string $component
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $component, string $message, array $context = []): void
    {
        $sql = "INSERT INTO system_logs (log_level, component, message, context_data) VALUES (?, ?, ?, ?)";

        try {
            $this->query($sql, [
                strtoupper($level),
                $component,
                $message,
                json_encode($context)
            ]);
        } catch (Exception $e) {
            // Evitar loops infinitos en logging
            error_log("Error guardando log en BD: " . $e->getMessage());
        }
    }

    /**
     * Obtiene estadísticas del sistema
     * @return array<string, mixed>
     * @throws Exception
     */
    public function getSystemStats(): array
    {
        /** @var array<string, mixed> $stats */
        $stats = [];

        // Estadísticas de tablas principales
        /** @var string[] $tables */
        $tables = ['bulk_searches', 'search_batches', 'local_database_records', 'external_results'];
        foreach ($tables as $table) {
            $sql = "SELECT COUNT(*) as count FROM {$table}";
            $stmt = $this->query($sql);
            /** @var array{count: string}|false $result */
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['tables'][$table] = (int)($result['count'] ?? 0);
        }

        // Estadísticas de consultas en esta sesión
        $stats['queries'] = [
            'total_queries' => $this->queryCount,
            'total_time_ms' => round($this->totalQueryTime, 2),
            'avg_time_ms' => $this->queryCount > 0 ? round($this->totalQueryTime / $this->queryCount, 2) : 0.0
        ];

        return $stats;
    }

    /**
     * Verifica la salud de la conexión
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        try {
            $startTime = microtime(true);
            $stmt = $this->query("SELECT 1 as health_check, version() as version");
            /** @var array{health_check: int, version: string}|false $result */
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $responseTime = (microtime(true) - $startTime) * 1000;

            if ($result === false) {
                return [
                    'status' => 'unhealthy',
                    'error' => 'Failed to fetch health check data.',
                    'connection_active' => false
                ];
            }

            return [
                'status' => 'healthy',
                'response_time_ms' => round($responseTime, 2),
                'version' => $result['version'],
                'connection_active' => true
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connection_active' => false
            ];
        }
    }

    /**
     * Cierra la conexión
     */
    public function close(): void
    {
        $this->connection = null;
        $this->logger->info('Conexión a PostgreSQL cerrada');
    }
}
