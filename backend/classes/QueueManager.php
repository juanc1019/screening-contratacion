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
    private array $config;
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
     */
    public function processQueue(): array
    {
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
     */
    private function executeBatchLocalSearch(array $job): array
    {
        $data = $job['data'];
        $batchId = $data['batch_id'];
        $config = $data['config'] ?? [];
        
        $searchEngine = new SearchEngine();
        $result = $searchEngine->searchBatch($batchId, $config);
        
        // Actualizar progreso
        $this->updateJobProgress($job['id'], 100);
        
        return $result;
    }
    
    /**
     * Ejecuta búsqueda externa masiva
     */
    private function executeBatchExternalSearch(array $job): array
    {
        $data = $job['data'];
        $batchId = $data['batch_id'];
        $selectedSites = $data['selected_sites'] ?? [];
        $options = $data['options'] ?? [];
        
        $scraperManager = new ScraperManager();
        $result = $scraperManager->searchBatch($batchId, $selectedSites, $options);
        
        $this->updateJobProgress($job['id'], 100);
        
        return $result;
    }
    
    /**
     * Ejecuta búsqueda individual
     */
    private function executeIndividualSearch(array $job): array
    {
        $data = $job['data'];
        $searchTerm = $data['search_term'];
        $searchType = $data['search_type'] ?? 'name';
        $includeLocal = $data['include_local'] ?? true;
        $includeExternal = $data['include_external'] ?? true;
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
     */
    private function executeExcelProcessing(array $job): array
    {
        $data = $job['data'];
        $filePath = $data['file_path'];
        $fileType = $data['file_type'] ?? 'search';
        
        $processor = new ExcelProcessor();
        $result = $processor->processFile($filePath, $fileType);
        
        $this->updateJobProgress($job['id'], 100);
        
        return $result;
    }
    
    /**
     * Ejecuta limpieza del sistema
     */
    private function executeCleanup(array $job): array
    {
        $data = $job['data'];
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
            $files = glob($logDir . '/*.log');
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
            $files = glob($pattern);
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
     */
    private function getQueuedJobs(int $limit): array
    {
        $queued = [];
        
        foreach ($this->activeJobs as $job) {
            if ($job['status'] === 'queued') {
                $queued[] = $job;
            }
        }
        
        // Ordenar por prioridad (mayor primero) y luego por fecha de creación
        usort($queued, function($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['created_at'] <=> $b['created_at'];
            }
            return $b['priority'] <=> $a['priority'];
        });
        
        return array_slice($queued, 0, $limit);
    }
    
    /**
     * Obtiene el estado de un trabajo
     */
    public function getJobStatus(string $jobId): ?array
    {
        return $this->activeJobs[$jobId] ?? null;
    }
    
    /**
     * Obtiene todos los trabajos activos
     */
    public function getActiveJobs(): array
    {
        return array_values($this->activeJobs);
    }
    
    /**
     * Obtiene estadísticas de la cola
     */
    public function getQueueStats(): array
    {
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
            $stats[$job['status']]++;
            
            if ($job['status'] === 'completed' && $job['started_at'] && $job['completed_at']) {
                $executionTime = $job['completed_at'] - $job['started_at'];
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
            if (in_array($job['status'], ['completed', 'failed', 'cancelled']) 
                && $job['completed_at'] < $cutoff) {
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
     */
    public function healthCheck(): array
    {
        $stats = $this->getQueueStats();
        $health = [
            'status' => 'healthy',
            'issues' => []
        ];
        
        // Verificar si hay muchos trabajos fallidos
        if ($stats['failed'] > ($stats['total_jobs'] * 0.1)) {
            $health['status'] = 'warning';
            $health['issues'][] = 'Alto porcentaje de trabajos fallidos';
        }
        
        // Verificar si la cola está bloqueada
        if ($stats['running'] === 0 && $stats['queued'] > 0) {
            $health['status'] = 'critical';
            $health['issues'][] = 'Cola bloqueada - hay trabajos en espera pero ninguno ejecutándose';
        }
        
        // Verificar utilización de la cola
        if ($stats['queue_utilization'] > 90) {
            $health['status'] = 'warning';
            $health['issues'][] = 'Alta utilización de la cola';
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
     */
    public function processScheduledJobs(): array
    {
        $processed = [];
        $now = microtime(true);
        
        foreach ($this->activeJobs as $jobId => $job) {
            if ($job['status'] === 'scheduled' && 
                isset($job['scheduled_at']) && 
                $job['scheduled_at'] <= $now) {
                
                $this->activeJobs[$jobId]['status'] = 'queued';
                unset($this->activeJobs[$jobId]['scheduled_at']);
                
                $processed[] = $jobId;
                
                $this->logger->debug("Trabajo programado movido a cola", ['job_id' => $jobId]);
            }
        }
        
        return $processed;
    }
}