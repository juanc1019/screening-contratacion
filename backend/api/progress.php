<?php

/**
 * API para monitoreo de progreso en tiempo real
 * Proporciona actualizaciones de estado para búsquedas y procesamiento
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../utils/helpers.php'; // Include helpers

use ScreeningApp\Database;
use ScreeningApp\QueueManager;
use function ScreeningApp\Utils\sendSuccess; // Import specific functions
use function ScreeningApp\Utils\sendError;   // Import specific functions

// Headers para SSE (Server-Sent Events) o JSON regular
$isSSE = isset($_GET['stream']) && $_GET['stream'] === 'true';

if ($isSSE) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Cache-Control');
} else {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Solo permitir GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    // sendError already sets header and exits
    sendError('Método no permitido', 405);
}

try {
    $db = Database::getInstance();
    $queueManager = new QueueManager();

    $action = $_GET['action'] ?? 'batch_progress';

    if ($isSSE) {
        // Monitoreo continuo con Server-Sent Events
        handleSSEProgress($action, $db, $queueManager);
    } else {
        // Respuesta única JSON
        $result = handleSingleProgress($action, $db, $queueManager);
        // For non-SSE, assuming $result already contains 'success' => true
        // and any necessary data. If it's purely data, use sendSuccess.
        // Let's assume functions like getBatchProgressData return the full structure including 'success'.
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
} catch (Exception $e) {
    error_log("Error en progress.php: " . $e->getMessage());

    if ($isSSE) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
        flush();
        // Consider closing SSE connection or allowing client to handle retry
    } else {
        sendError($e->getMessage(), 400);
    }
}

/**
 * Maneja progreso continuo con Server-Sent Events
 */
function handleSSEProgress(string $action, Database $db, QueueManager $queueManager): void
{
    // Configurar timeout y límites
    set_time_limit(0);
    ignore_user_abort(false);

    $batchId = $_GET['batch_id'] ?? null;
    $jobId = $_GET['job_id'] ?? null;
    $interval = max(1, (int)($_GET['interval'] ?? 2)); // Segundos entre actualizaciones

    $startTime = time();
    $maxDuration = 300; // 5 minutos máximo

    while (time() - $startTime < $maxDuration && connection_status() === CONNECTION_NORMAL) {
        $data = [];

        try {
            switch ($action) {
                case 'batch_progress':
                    if ($batchId) {
                        $data = getBatchProgressData($batchId, $db);
                    }
                    break;

                case 'job_progress':
                    if ($jobId) {
                        $data = getJobProgressData($jobId, $queueManager);
                    }
                    break;

                case 'queue_status':
                    $data = getQueueStatusData($queueManager);
                    break;

                case 'system_status':
                    $data = getSystemStatusData($db, $queueManager);
                    break;
            }

            // Enviar datos
            echo "event: progress\n";
            echo "data: " . json_encode(array_merge($data, [
                'timestamp' => date('Y-m-d H:i:s'),
                'server_time' => time()
            ])) . "\n\n";

            flush();

            // Verificar si el proceso está completo
            if (isset($data['completed']) && $data['completed']) {
                echo "event: complete\n";
                echo "data: " . json_encode(['message' => 'Proceso completado']) . "\n\n";
                flush();
                break;
            }
        } catch (Exception $e) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            flush();
        }

        sleep($interval);
    }

    // Enviar evento de cierre
    echo "event: close\n";
    echo "data: " . json_encode(['message' => 'Stream cerrado']) . "\n\n";
    flush();
}

/**
 * Maneja respuesta única de progreso
 * @return array<string, mixed>
 */
function handleSingleProgress(string $action, Database $db, QueueManager $queueManager): array
{
    switch ($action) {
        case 'batch_progress':
            $batchId = $_GET['batch_id'] ?? null;
            if (!$batchId) {
                throw new Exception('batch_id requerido');
            }
            return getBatchProgressData($batchId, $db);

        case 'job_progress':
            $jobId = $_GET['job_id'] ?? null;
            if (!$jobId) {
                throw new Exception('job_id requerido');
            }
            return getJobProgressData($jobId, $queueManager);

        case 'queue_status':
            return getQueueStatusData($queueManager);

        case 'system_status':
            return getSystemStatusData($db, $queueManager);

        case 'notifications':
            $limit = (int)($_GET['limit'] ?? 10);
            return getNotificationsData($db, $limit);

        case 'recent_activity':
            $limit = (int)($_GET['limit'] ?? 20);
            return getRecentActivityData($db, $limit);

        default:
            throw new Exception('Acción no válida');
    }
}

/**
 * Obtiene datos de progreso de un lote
 * @return array<string, mixed>
 */
function getBatchProgressData(string $batchId, Database $db): array
{
    $progress = $db->getBatchProgress($batchId);

    if (empty($progress)) {
        throw new Exception('Lote no encontrado');
    }

    // Obtener detalles adicionales
    $sql = "SELECT 
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'pending') as pending,
                COUNT(*) FILTER (WHERE status = 'processing') as processing,
                COUNT(*) FILTER (WHERE status = 'completed') as completed,
                COUNT(*) FILTER (WHERE status = 'error') as errors
            FROM bulk_searches 
            WHERE batch_id = ?";

    $stmt = $db->query($sql, [$batchId]);
    /** @var array<string, int|string|null>|false $statusCounts */
    $statusCounts = $stmt->fetch(PDO::FETCH_ASSOC);

    // Estadísticas de resultados
    /** @var array<int, array<string, mixed>> $resultStats */
    $resultStats = $db->getSearchResultsSummary($batchId);

    $localMatches = 0;
    $externalMatches = 0;
    foreach ($resultStats as $result) {
        $localMatches += (int)($result['local_matches'] ?? 0);
        $externalMatches += (int)($result['external_matches'] ?? 0);
    }

    $totalRecordsWithResults = 0;
    foreach ($resultStats as $r) {
        if (((int)($r['local_matches'] ?? 0) + (int)($r['external_matches'] ?? 0)) > 0) {
            $totalRecordsWithResults++;
        }
    }

    return [
        'success' => true,
        'batch_id' => $batchId,
        'batch_info' => $progress,
        'progress_percentage' => (float)($progress['progress_percentage'] ?? 0.0),
        'status_counts' => $statusCounts ?: [],
        'results_summary' => [
            'total_local_matches' => $localMatches,
            'total_external_matches' => $externalMatches,
            'records_with_results' => $totalRecordsWithResults
        ],
        'completed' => ($progress['status'] ?? 'unknown') === 'completed',
        'estimated_remaining_time' => estimateRemainingTime($progress),
        'current_phase' => getCurrentPhase($progress)
    ];
}

/**
 * Obtiene datos de progreso de un trabajo
 * @return array<string, mixed>
 */
function getJobProgressData(string $jobId, QueueManager $queueManager): array
{
    $job = $queueManager->getJobStatus($jobId);

    if (!$job) {
        throw new Exception('Trabajo no encontrado');
    }

    $executionTime = 0.0;
    if (!empty($job['started_at'])) {
        /** @var float $startTime */
        $startTime = $job['started_at'];
        /** @var float $endTime */
        $endTime = $job['completed_at'] ?? microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // en ms
    }

    return [
        'success' => true,
        'job_id' => $jobId,
        'job_info' => $job,
        'progress_percentage' => (float)($job['progress'] ?? 0.0),
        'execution_time_ms' => round($executionTime, 2),
        'completed' => in_array(($job['status'] ?? 'unknown'), ['completed', 'failed', 'cancelled']),
        'estimated_remaining_time' => estimateJobRemainingTime($job)
    ];
}

/**
 * Obtiene estado de la cola
 * @return array<string, mixed>
 */
function getQueueStatusData(QueueManager $queueManager): array
{
    $stats = $queueManager->getQueueStats();
    /** @var array<int, array<string, mixed>> $activeJobs */
    $activeJobs = $queueManager->getActiveJobs();

    // Obtener trabajos por tipo
    /** @var array<string, array<string, int>> $jobsByType */
    $jobsByType = [];
    foreach ($activeJobs as $job) {
        /** @var string $type */
        $type = $job['type'];
        if (!isset($jobsByType[$type])) {
            $jobsByType[$type] = ['total' => 0, 'running' => 0, 'queued' => 0];
        }
        $jobsByType[$type]['total']++;
        if (($job['status'] ?? 'unknown') === 'running') {
            $jobsByType[$type]['running']++;
        } elseif (($job['status'] ?? 'unknown') === 'queued') {
            $jobsByType[$type]['queued']++;
        }
    }

    return [
        'success' => true,
        'queue_stats' => $stats,
        'jobs_by_type' => $jobsByType,
        'active_jobs' => array_slice($activeJobs, 0, 10), // Solo los primeros 10
        'health_status' => $queueManager->healthCheck()
    ];
}

/**
 * Obtiene estado general del sistema
 * @return array<string, mixed>
 */
function getSystemStatusData(Database $db, QueueManager $queueManager): array
{
    /** @var array<string, mixed> $dbHealth */
    $dbHealth = $db->healthCheck();
    /** @var array<string, mixed> $queueHealth */
    $queueHealth = $queueManager->healthCheck();
    /** @var array<string, mixed> $systemStats */
    $systemStats = $db->getSystemStats();

    // Calcular uso de memoria y CPU (básico)
    $memoryUsage = memory_get_usage(true);
    $memoryPeak = memory_get_peak_usage(true);

    return [
        'success' => true,
        'database' => $dbHealth,
        'queue' => $queueHealth,
        'system_stats' => $systemStats,
        'performance' => [
            'memory_usage_mb' => round($memoryUsage / (1024 * 1024), 2),
            'memory_peak_mb' => round($memoryPeak / (1024 * 1024), 2),
            'uptime_seconds' => time() - (int)($_SERVER['REQUEST_TIME_FLOAT'] ?? $_SERVER['REQUEST_TIME']),
            'php_version' => PHP_VERSION
        ],
        'overall_status' => determineOverallStatus($dbHealth, $queueHealth)
    ];
}

/**
 * Obtiene notificaciones recientes
 * @return array<string, mixed>
 */
function getNotificationsData(Database $db, int $limit): array
{
    /** @var array<int, array<string, mixed>> $notifications */
    $notifications = $db->getUnreadNotifications();

    return [
        'success' => true,
        'notifications' => array_slice($notifications, 0, $limit),
        'unread_count' => count($notifications)
    ];
}

/**
 * Obtiene actividad reciente del sistema
 * @return array<string, mixed>
 */
function getRecentActivityData(Database $db, int $limit): array
{
    $sql = "SELECT log_level, component, message, created_at 
            FROM system_logs 
            WHERE log_level IN ('INFO', 'WARNING', 'ERROR')
            ORDER BY created_at DESC 
            LIMIT ?";

    $stmt = $db->query($sql, [$limit]);
    /** @var array<int, array<string, mixed>> $logs */
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'recent_activity' => $logs,
        'activity_count' => count($logs)
    ];
}

/**
 * Estima tiempo restante para un lote
 * @param array<string, mixed> $progress
 */
function estimateRemainingTime(array $progress): string
{
    if (($progress['status'] ?? 'unknown') === 'completed') {
        return 'Completado';
    }

    $totalRecords = (int)($progress['total_records'] ?? 0);
    $processedRecords = (int)($progress['processed_records'] ?? 0);
    $progressPercentage = (float)($progress['progress_percentage'] ?? 0.0);

    if ($processedRecords === 0 || abs($progressPercentage) < 0.00001) {
        return 'Calculando...';
    }

    /** @var string|int $startTimeString */
    $startTimeString = $progress['started_at'] ?? $progress['created_at'] ?? time();
    $startTime = is_numeric($startTimeString) ? (int)$startTimeString : strtotime((string)$startTimeString);
    if ($startTime === false) {
        $startTime = time(); // Fallback if strtotime fails
    }

    $elapsedTime = time() - $startTime;
    $estimatedTotalTime = ($elapsedTime / $progressPercentage) * 100;
    $remainingTime = max(0.0, $estimatedTotalTime - $elapsedTime);

    if ($remainingTime < 60) {
        return round($remainingTime) . ' segundos';
    } elseif ($remainingTime < 3600) {
        return round($remainingTime / 60) . ' minutos';
    } else {
        return round($remainingTime / 3600, 1) . ' horas';
    }
}

/**
 * Estima tiempo restante para un trabajo
 * @param array<string, mixed> $job
 */
function estimateJobRemainingTime(array $job): string
{
    if (in_array(($job['status'] ?? 'unknown'), ['completed', 'failed', 'cancelled'])) {
        return 'Terminado';
    }

    $progress = (float)($job['progress'] ?? 0.0);
    /** @var float|null $startedAt */
    $startedAt = $job['started_at'] ?? null;

    if (abs($progress) < 0.00001 || $startedAt === null) {
        return 'Calculando...';
    }

    $elapsedTime = microtime(true) - $startedAt;
    $estimatedTotalTime = ($elapsedTime / $progress) * 100;
    $remainingTime = max(0.0, $estimatedTotalTime - $elapsedTime);

    if ($remainingTime < 60) {
        return round($remainingTime) . ' segundos';
    } else {
        return round($remainingTime / 60) . ' minutos';
    }
}

/**
 * Obtiene la fase actual de procesamiento
 * @param array<string, mixed> $progress
 */
function getCurrentPhase(array $progress): string
{
    $percentage = (float)($progress['progress_percentage'] ?? 0.0);

    if (abs($percentage) < 0.00001) { // Check against a small epsilon instead of === 0 for float
        return 'Iniciando';
    } elseif ($percentage < 50.0) {
        return 'Búsqueda local';
    } elseif ($percentage < 90.0) {
        return 'Búsqueda externa';
    } elseif ($percentage < 100.0) {
        return 'Finalizando';
    } else {
        return 'Completado';
    }
}

/**
 * Determina el estado general del sistema
 * @param array<string, mixed> $dbHealth
 * @param array<string, mixed> $queueHealth
 */
function determineOverallStatus(array $dbHealth, array $queueHealth): string
{
    if (($dbHealth['status'] ?? 'unknown') === 'unhealthy') {
        return 'critical';
    }

    if (($queueHealth['status'] ?? 'unknown') === 'critical') {
        return 'critical';
    }

    if (($dbHealth['status'] ?? 'unknown') === 'healthy' && ($queueHealth['status'] ?? 'unknown') === 'healthy') {
        return 'healthy';
    }

    return 'warning';
}
