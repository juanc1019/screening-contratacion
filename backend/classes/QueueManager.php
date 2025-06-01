<?php

namespace ScreeningApp;

use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Clase QueueManager - Sistema de colas para controlar carga del servidor
 * Evita saturación con límites configurables y procesamiento inteligente
 */
class QueueManager
{
    private Database $db;
    private Logger $logger;
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, array<string, mixed>> */
    private array $activeJobs;
    private int $maxConcurrentJobs;
    private int $maxConcurrentScrapers;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/app.php';
        $this->setupLogger();
        $this->initializeQueue();
    }

    /**
     * Configura el logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('queue_manager');
        $logFile = $this->config['logging']['files']['application'] ?? 'logs/application.log';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }

    /**
     * Inicializa el sistema de colas
     */
    private function initializeQueue(): void
    {
        $this->activeJobs = [];
        $this->maxConcurrentJobs = $this->config['search']['max_concurrent_searches'] ?? 5;
        $this->maxConcurrentScrapers = $this->config['search']['max_concurrent_scrapers'] ?? 3;

        $this->logger->info("QueueManager inicializado", [
            'max_concurrent_jobs' => $this->maxConcurrentJobs,
            'max_concurrent_scrapers' => $this->maxConcurrentScrapers
        ]);
    }

    /**
     * Añade un trabajo a la cola
     * @param string $type
     * @param array<string, mixed> $data
     * @param int $priority
     * @return string
     */
    public function addJob(string $type, array $data, int $priority = 1): string
    {
        $jobId = $this->generateJobId();

        $job = [
            'id' => $jobId,
            'type' => $type,
            'data' => $data,
            'priority' => $priority,
            'status' => 'queued',
            'created_at' => microtime(true),
            'started_at' => null,
            'completed_at' => null,
            'error' => null,
            'progress' => 0,
            'result' => null
        ];

        // Guardar en memoria (en producción usarías Redis o base de datos)
        $this->activeJobs[$jobId] = $job;

        $this->logger->info("Trabajo añadido a la cola", [
            'job_id' => $jobId,
            'type' => $type,
            'priority' => $priority
        ]);

        return $jobId;
    }

    /**
     * Procesa trabajos en la cola
     * @return string[]
     */
    public function processQueue(): array
    {
        /** @var string[] $processed */
        $processed = [];
        $currentRunning = $this->countRunningJobs();

        if ($currentRunning >= $this->maxConcurrentJobs) {
            $this->logger->debug("Cola llena, esperando trabajos disponibles", [
                'running' => $currentRunning,
                'max' => $this->maxConcurrentJobs
            ]);
            return $processed;
        }

        // Obtener trabajos pendientes ordenados por prioridad
        $queuedJobs = $this->getQueuedJobs($this->maxConcurrentJobs - $currentRunning);

        foreach ($queuedJobs as $job) {
            try {
                $this->startJob($job['id']);
                $result = $this->executeJob($job);
                $this->completeJob($job['id'], $result);
                $processed[] = $job['id'];
            } catch (Exception $e) {
                $this->failJob($job['id'], $e->getMessage());
                $this->logger->error("Error ejecutando trabajo", [
                    'job_id' => $job['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $processed;
    }

    /**
     * Ejecuta un trabajo específico
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function executeJob(array $job): array
    {
        $this->logger->info("Ejecutando trabajo", [
            'job_id' => $job['id'],
            'type' => $job['type']
        ]);

        switch ($job['type']) {
            case 'batch_local_search':
                return $this->executeBatchLocalSearch($job);

            case 'batch_external_search':
                return $this->executeBatchExternalSearch($job);

            case 'individual_search':
                return $this->executeIndividualSearch($job);

            case 'excel_processing':
                return $this->executeExcelProcessing($job);

            case 'cleanup':
                return $this->executeCleanup($job);

            default:
                throw new Exception("Tipo de trabajo no soportado: {$job['type']}");
        }
    }

    /**
     * Ejecuta búsqueda local masiva
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function executeBatchLocalSearch(array $job): array
    {
        /** @var array<string, mixed> $data */
        $data = $job['data'];
        /** @var string $batchId */
        $batchId = $data['batch_id'];
        /** @var array<string, mixed> $config */
        $config = $data['config'] ?? [];

        $searchEngine = new SearchEngine();
        $result = $searchEngine->searchBatch($batchId, $config);

        // Actualizar progreso
        $this->updateJobProgress($job['id'], 100);

        return $result;
    }

    /**
     * Ejecuta búsqueda externa masiva
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function executeBatchExternalSearch(array $job): array
    {
        /** @var array<string, mixed> $data */
        $data = $job['data'];
        /** @var string $batchId */
        $batchId = $data['batch_id'];
        /** @var string[] $selectedSites */
        $selectedSites = $data['selected_sites'] ?? [];
        /** @var array<string, mixed> $options */
        $options = $data['options'] ?? [];

        $scraperManager = new ScraperManager();
        $result = $scraperManager->searchBatch($batchId, $selectedSites, $options);

        $this->updateJobProgress($job['id'], 100);

        return $result;
    }

    /**
     * Ejecuta búsqueda individual
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function executeIndividualSearch(array $job): array
    {
        /** @var array<string, mixed> $data */
        $data = $job['data'];
        /** @var string $searchTerm */
        $searchTerm = $data['search_term'];
        /** @var string $searchType */
        $searchType = $data['search_type'] ?? 'name';
        /** @var bool $includeLocal */
        $includeLocal = $data['include_local'] ?? true;
        /** @var bool $includeExternal */
        $includeExternal = $data['include_external'] ?? true;
        /** @var string[] $selectedSites */
        $selectedSites = $data['selected_sites'] ?? [];

        $results = [
            'local_results' => [],
            'external_results' => [],
            'execution_times' => []
        ];

        // Búsqueda local
        if ($includeLocal) {
            $startTime = microtime(true);
            $searchEngine = new SearchEngine();
            $localResult = $searchEngine->searchIndividual($searchTerm, $searchType);
            $results['local_results'] = $localResult;
            $results['execution_times']['local'] = (microtime(true) - $startTime) * 1000;

            $this->updateJobProgress($job['id'], 50);
        }

        // Búsqueda externa
        if ($includeExternal) {
            $startTime = microtime(true);
            $scraperManager = new ScraperManager();
            $externalResult = $scraperManager->searchIndividual($searchTerm, $selectedSites);
            $results['external_results'] = $externalResult;
            $results['execution_times']['external'] = (microtime(true) - $startTime) * 1000;

            $this->updateJobProgress($job['id'], 100);
        }

        return $results;
    }

    /**
     * Ejecuta procesamiento de Excel
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function executeExcelProcessing(array $job): array
    {
        /** @var array<string, mixed> $data */
        $data = $job['data'];
        /** @var string $filePath */
        $filePath = $data['file_path'];
        /** @var string $fileType */
        $fileType = $data['file_type'] ?? 'search';

        $processor = new ExcelProcessor();
        $result = $processor->processFile($filePath, $fileType);

        $this->updateJobProgress($job['id'], 100);

        return $result;
    }

    /**
     * Ejecuta limpieza del sistema
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function executeCleanup(array $job): array
    {
        /** @var array<string, mixed> $data */
        $data = $job['data'];
        /** @var string $cleanupType */
        $cleanupType = $data['type'] ?? 'all';

        $cleaned = [
            'logs' => 0,
            'old_batches' => 0,
            'temp_files' => 0,
            'notifications' => 0
        ];

        try {
            switch ($cleanupType) {
                case 'logs':
                    $cleaned['logs'] = $this->cleanupLogs();
                    break;

                case 'old_batches':
                    $cleaned['old_batches'] = $this->cleanupOldBatches();
                    break;

                case 'temp_files':
                    $cleaned['temp_files'] = $this->cleanupTempFiles();
                    break;

                case 'notifications':
                    $cleaned['notifications'] = $this->cleanupNotifications();
                    break;

                case 'all':
                    $cleaned['logs'] = $this->cleanupLogs();
                    $this->updateJobProgress($job['id'], 25);

                    $cleaned['old_batches'] = $this->cleanupOldBatches();
                    $this->updateJobProgress($job['id'], 50);

                    $cleaned['temp_files'] = $this->cleanupTempFiles();
                    $this->updateJobProgress($job['id'], 75);

                    $cleaned['notifications'] = $this->cleanupNotifications();
                    $this->updateJobProgress($job['id'], 100);
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error("Error en limpieza", ['error' => $e->getMessage()]);
            throw $e;
        }

        return [
            'success' => true,
            'cleaned' => $cleaned,
            'type' => $cleanupType
        ];
    }

    /**
     * Limpia logs antiguos
     */
    private function cleanupLogs(): int
    {
        $logDir = $this->config['paths']['logs'];
        $maxAge = 30; // días
        $cleaned = 0;

        if (is_dir($logDir)) {
            /** @var array|false $files */
            $files = glob($logDir . '/*.log');
            if ($files === false) {
                return 0; // Error globbing
            }
            foreach ($files as $file) {
                if (filemtime($file) < strtotime("-{$maxAge} days")) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }

    /**
     * Limpia lotes antiguos
     */
    private function cleanupOldBatches(): int
    {
        $sql = "DELETE FROM search_batches 
                WHERE status = 'completed' 
                AND completed_at < NOW() - INTERVAL '30 days'";

        $stmt = $this->db->query($sql);
        return $stmt->rowCount();
    }

    /**
     * Limpia archivos temporales
     */
    private function cleanupTempFiles(): int
    {
        $tempDirs = [
            $this->config['paths']['uploads'] . '/temp',
            sys_get_temp_dir() . '/screening_*'
        ];

        $cleaned = 0;
        foreach ($tempDirs as $pattern) {
            /** @var array|false $files */
            $files = glob($pattern);
            if ($files === false) {
                continue; // Error globbing pattern
            }
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < strtotime('-1 day')) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                } elseif (is_dir($file) && $this->isDirEmpty($file)) {
                    if (rmdir($file)) {
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }

    /**
     * Limpia notificaciones antiguas
     */
    private function cleanupNotifications(): int
    {
        $sql = "DELETE FROM notifications 
                WHERE is_read = true 
                AND read_at < NOW() - INTERVAL '7 days'";

        $stmt = $this->db->query($sql);
        return $stmt->rowCount();
    }

    /**
     * Verifica si un directorio está vacío
     */
    private function isDirEmpty(string $dir): bool
    {
        $handle = opendir($dir);
        if ($handle === false) {
            return true; // Cannot open, assume empty or inaccessible
        }
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * Inicia un trabajo
     */
    private function startJob(string $jobId): void
    {
        if (isset($this->activeJobs[$jobId])) {
            $this->activeJobs[$jobId]['status'] = 'running';
            $this->activeJobs[$jobId]['started_at'] = microtime(true);

            $this->logger->debug("Trabajo iniciado", ['job_id' => $jobId]);
        }
    }

    /**
     * Completa un trabajo
     * @param string $jobId
     * @param array<string, mixed> $result
     */
    private function completeJob(string $jobId, array $result): void
    {
        if (isset($this->activeJobs[$jobId])) {
            $this->activeJobs[$jobId]['status'] = 'completed';
            $this->activeJobs[$jobId]['completed_at'] = microtime(true);
            $this->activeJobs[$jobId]['result'] = $result;
            $this->activeJobs[$jobId]['progress'] = 100;

            $executionTime = $this->activeJobs[$jobId]['completed_at'] - $this->activeJobs[$jobId]['started_at'];

            $this->logger->info("Trabajo completado", [
                'job_id' => $jobId,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
        }
    }

    /**
     * Marca un trabajo como fallido
     */
    private function failJob(string $jobId, string $error): void
    {
        if (isset($this->activeJobs[$jobId])) {
            $this->activeJobs[$jobId]['status'] = 'failed';
            $this->activeJobs[$jobId]['completed_at'] = microtime(true);
            $this->activeJobs[$jobId]['error'] = $error;

            $this->logger->error("Trabajo fallido", [
                'job_id' => $jobId,
                'error' => $error
            ]);
        }
    }

    /**
     * Actualiza el progreso de un trabajo
     */
    private function updateJobProgress(string $jobId, int $progress): void
    {
        if (isset($this->activeJobs[$jobId])) {
            $this->activeJobs[$jobId]['progress'] = min(100, max(0, $progress));

            $this->logger->debug("Progreso actualizado", [
                'job_id' => $jobId,
                'progress' => $progress
            ]);
        }
    }

    /**
     * Cuenta trabajos en ejecución
     */
    private function countRunningJobs(): int
    {
        $running = 0;
        foreach ($this->activeJobs as $job) {
            if ($job['status'] === 'running') {
                $running++;
            }
        }
        return $running;
    }

    /**
     * Obtiene trabajos en cola ordenados por prioridad
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function getQueuedJobs(int $limit): array
    {
        /** @var array<int, array<string, mixed>> $queued */
        $queued = [];

        foreach ($this->activeJobs as $job) {
            if (($job['status'] ?? 'unknown') === 'queued') {
                $queued[] = $job;
            }
        }

        // Ordenar por prioridad (mayor primero) y luego por fecha de creación
        usort($queued, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['created_at'] <=> $b['created_at'];
            }
            return $b['priority'] <=> $a['priority'];
        });

        return array_slice($queued, 0, $limit);
    }

    /**
     * Obtiene el estado de un trabajo
     * @param string $jobId
     * @return array<string, mixed>|null
     */
    public function getJobStatus(string $jobId): ?array
    {
        return $this->activeJobs[$jobId] ?? null;
    }

    /**
     * Obtiene todos los trabajos activos
     * @return array<int, array<string, mixed>>
     */
    public function getActiveJobs(): array
    {
        return array_values($this->activeJobs);
    }

    /**
     * Obtiene estadísticas de la cola
     * @return array<string, int|float>
     */
    public function getQueueStats(): array
    {
        /** @var array<string, int|float> $stats */
        $stats = [
            'total_jobs' => count($this->activeJobs),
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'average_execution_time' => 0,
            'queue_utilization' => 0
        ];

        $totalExecutionTime = 0;
        $completedJobs = 0;

        foreach ($this->activeJobs as $job) {
            /** @var string $status */
            $status = $job['status'] ?? 'unknown';
            $stats[$status] = ($stats[$status] ?? 0) + 1;

            if ($status === 'completed' && isset($job['started_at'], $job['completed_at'])) {
                /** @var float $startedAt */
                $startedAt = $job['started_at'];
                /** @var float $completedAt */
                $completedAt = $job['completed_at'];
                $executionTime = $completedAt - $startedAt;
                $totalExecutionTime += $executionTime;
                $completedJobs++;
            }
        }

        if ($completedJobs > 0) {
            $stats['average_execution_time'] = round(($totalExecutionTime / $completedJobs) * 1000, 2);
        }

        $stats['queue_utilization'] = round(($stats['running'] / $this->maxConcurrentJobs) * 100, 2);

        return $stats;
    }

    /**
     * Cancela un trabajo
     */
    public function cancelJob(string $jobId): bool
    {
        if (isset($this->activeJobs[$jobId]) && $this->activeJobs[$jobId]['status'] === 'queued') {
            $this->activeJobs[$jobId]['status'] = 'cancelled';
            $this->activeJobs[$jobId]['completed_at'] = microtime(true);

            $this->logger->info("Trabajo cancelado", ['job_id' => $jobId]);
            return true;
        }

        return false;
    }

    /**
     * Limpia trabajos completados antiguos
     */
    public function cleanupCompletedJobs(int $maxAge = 3600): int
    {
        $cleaned = 0;
        $cutoff = microtime(true) - $maxAge;

        foreach ($this->activeJobs as $jobId => $job) {
            if (
                in_array($job['status'], ['completed', 'failed', 'cancelled'])
                && $job['completed_at'] < $cutoff
            ) {
                unset($this->activeJobs[$jobId]);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->logger->info("Trabajos completados limpiados", ['count' => $cleaned]);
        }

        return $cleaned;
    }

    /**
     * Pausa la cola
     */
    public function pauseQueue(): void
    {
        $this->maxConcurrentJobs = 0;
        $this->logger->info("Cola pausada");
    }

    /**
     * Reanuda la cola
     */
    public function resumeQueue(): void
    {
        $this->maxConcurrentJobs = $this->config['search']['max_concurrent_searches'] ?? 5;
        $this->logger->info("Cola reanudada", ['max_concurrent' => $this->maxConcurrentJobs]);
    }

    /**
     * Ajusta límites de concurrencia
     */
    public function setConcurrencyLimits(int $maxJobs, int $maxScrapers): void
    {
        $this->maxConcurrentJobs = max(1, $maxJobs);
        $this->maxConcurrentScrapers = max(1, $maxScrapers);

        $this->logger->info("Límites de concurrencia actualizados", [
            'max_jobs' => $this->maxConcurrentJobs,
            'max_scrapers' => $this->maxConcurrentScrapers
        ]);
    }

    /**
     * Verifica el estado de salud de la cola
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $stats = $this->getQueueStats();
        /** @var array<string, mixed> $health */
        $health = [
            'status' => 'healthy',
            'issues' => []
        ];

        // Verificar si hay muchos trabajos fallidos
        if (($stats['failed'] ?? 0) > (($stats['total_jobs'] ?? 1) * 0.1)) {
            $health['status'] = 'warning';
            /** @var string[] $healthIssues */
            $healthIssues = $health['issues'];
            $healthIssues[] = 'Alto porcentaje de trabajos fallidos';
            $health['issues'] = $healthIssues;
        }

        // Verificar si la cola está bloqueada
        if (($stats['running'] ?? 0) === 0 && ($stats['queued'] ?? 0) > 0) {
            $health['status'] = 'critical';
            /** @var string[] $healthIssues */
            $healthIssues = $health['issues'];
            $healthIssues[] = 'Cola bloqueada - hay trabajos en espera pero ninguno ejecutándose';
            $health['issues'] = $healthIssues;
        }

        // Verificar utilización de la cola
        if (($stats['queue_utilization'] ?? 0) > 90) {
            $health['status'] = 'warning';
            /** @var string[] $healthIssues */
            $healthIssues = $health['issues'];
            $healthIssues[] = 'Alta utilización de la cola';
            $health['issues'] = $healthIssues;
        }

        return array_merge($health, $stats);
    }

    /**
     * Genera ID único para trabajo
     */
    private function generateJobId(): string
    {
        return 'job_' . uniqid() . '_' . time();
    }

    /**
     * Programa trabajo para ejecución
     * @param string $type
     * @param array<string, mixed> $data
     * @param int $delaySeconds
     * @param int $priority
     * @return string
     */
    public function scheduleJob(string $type, array $data, int $delaySeconds = 0, int $priority = 1): string
    {
        $jobId = $this->addJob($type, $data, $priority);

        if ($delaySeconds > 0) {
            $this->activeJobs[$jobId]['scheduled_at'] = microtime(true) + $delaySeconds;
            $this->activeJobs[$jobId]['status'] = 'scheduled';

            $this->logger->info("Trabajo programado", [
                'job_id' => $jobId,
                'delay_seconds' => $delaySeconds
            ]);
        }

        return $jobId;
    }

    /**
     * Procesa trabajos programados
     * @return string[]
     */
    public function processScheduledJobs(): array
    {
        /** @var string[] $processed */
        $processed = [];
        $now = microtime(true);

        foreach ($this->activeJobs as $jobId => $job) {
            if (
                ($job['status'] ?? 'unknown') === 'scheduled' &&
                isset($job['scheduled_at']) &&
                (float)$job['scheduled_at'] <= $now
            ) {
                $this->activeJobs[$jobId]['status'] = 'queued';
                unset($this->activeJobs[$jobId]['scheduled_at']);

                $processed[] = $jobId;

                $this->logger->debug("Trabajo programado movido a cola", ['job_id' => $jobId]);
            }
        }

        return $processed;
    }
}
