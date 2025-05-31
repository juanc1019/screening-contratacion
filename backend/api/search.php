<?php

/**
 * API para búsquedas individuales y masivas
 * Maneja búsquedas locales y externas con control de carga
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use ScreeningApp\Database;
use ScreeningApp\SearchEngine;
use ScreeningApp\ScraperManager;
use ScreeningApp\QueueManager;
use ScreeningApp\ExcelProcessor;

// Headers CORS y JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $config = require __DIR__ . '/../config/app.php';
    $db = Database::getInstance();
    $requestMethod = $_SERVER['REQUEST_METHOD'];
    
    // GET: Obtener información de búsquedas
    if ($requestMethod === 'GET') {
        $action = $_GET['action'] ?? 'status';
        
        switch ($action) {
            case 'sites':
                // Obtener sitios disponibles para scrapers
                $scraperManager = new ScraperManager();
                $sites = $scraperManager->getSitesByCategory();
                
                echo json_encode([
                    'success' => true,
                    'sites' => $sites,
                    'total_sites' => count($scraperManager->getAvailableSites())
                ]);
                break;
                
            case 'batch_status':
                // Estado de un lote específico
                $batchId = $_GET['batch_id'] ?? null;
                if (!$batchId) {
                    throw new Exception('batch_id requerido');
                }
                
                $progress = $db->getBatchProgress($batchId);
                echo json_encode([
                    'success' => true,
                    'batch_progress' => $progress
                ]);
                break;
                
            case 'recent_searches':
                // Búsquedas recientes
                $limit = (int)($_GET['limit'] ?? 10);
                $results = $db->getSearchResultsSummary();
                
                echo json_encode([
                    'success' => true,
                    'recent_searches' => array_slice($results, 0, $limit)
                ]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        exit;
    }
    
    // Solo POST para búsquedas
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }
    
    // Leer datos POST
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido en el cuerpo de la petición');
    }
    
    $searchType = $input['search_type'] ?? 'individual';
    
    switch ($searchType) {
        case 'individual':
            $result = handleIndividualSearch($input, $config, $db);
            break;
            
        case 'batch':
            $result = handleBatchSearch($input, $config, $db);
            break;
            
        case 'create_batch':
            $result = handleCreateBatch($input, $config, $db);
            break;
            
        default:
            throw new Exception('Tipo de búsqueda no válido');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error en search.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Log del error
    try {
        $db = Database::getInstance();
        $db->log('ERROR', 'search_api', 'Error en búsqueda', [
            'error' => $e->getMessage(),
            'request_data' => $input ?? []
        ]);
    } catch (Exception $logError) {
        error_log("Error adicional en logging: " . $logError->getMessage());
    }
}

/**
 * Maneja búsquedas individuales
 */
function handleIndividualSearch(array $input, array $config, Database $db): array
{
    $searchTerm = trim($input['search_term'] ?? '');
    $searchMode = $input['search_mode'] ?? 'name'; // 'name', 'identification', 'both'
    $includeLocal = $input['include_local'] ?? true;
    $includeExternal = $input['include_external'] ?? true;
    $selectedSites = $input['selected_sites'] ?? [];
    $minSimilarity = (float)($input['min_similarity'] ?? $config['search']['similarity']['default_threshold']);
    
    if (empty($searchTerm)) {
        throw new Exception('Término de búsqueda requerido');
    }
    
    $queueManager = new QueueManager();
    
    // Para búsquedas rápidas, ejecutar inmediatamente
    if (!$includeExternal || count($selectedSites) <= 3) {
        $results = [
            'local_results' => [],
            'external_results' => [],
            'execution_times' => []
        ];
        
        // Búsqueda local
        if ($includeLocal) {
            $startTime = microtime(true);
            $searchEngine = new SearchEngine();
            $localResult = $searchEngine->searchIndividual($searchTerm, $searchMode, $minSimilarity);
            $results['local_results'] = $localResult;
            $results['execution_times']['local'] = (microtime(true) - $startTime) * 1000;
        }
        
        // Búsqueda externa
        if ($includeExternal && !empty($selectedSites)) {
            $startTime = microtime(true);
            $scraperManager = new ScraperManager();
            $externalResult = $scraperManager->searchIndividual($searchTerm, $selectedSites);
            $results['external_results'] = $externalResult;
            $results['execution_times']['external'] = (microtime(true) - $startTime) * 1000;
        }
        
        // Guardar en historial de búsquedas individuales
        $db->query("INSERT INTO individual_searches (search_term, search_type, selected_sites, total_sites, status, results_summary) VALUES (?, ?, ?, ?, ?, ?)", [
            $searchTerm,
            $searchMode,
            json_encode($selectedSites),
            count($selectedSites),
            'completed',
            json_encode([
                'local_matches' => count($results['local_results']['results'] ?? []),
                'external_sites_with_results' => count(array_filter($results['external_results']['results'] ?? [], fn($r) => $r['has_results']))
            ])
        ]);
        
        return [
            'success' => true,
            'search_type' => 'individual',
            'search_term' => $searchTerm,
            'results' => $results,
            'processed_immediately' => true,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } else {
        // Para búsquedas complejas, usar cola
        $jobId = $queueManager->addJob('individual_search', [
            'search_term' => $searchTerm,
            'search_type' => $searchMode,
            'include_local' => $includeLocal,
            'include_external' => $includeExternal,
            'selected_sites' => $selectedSites,
            'min_similarity' => $minSimilarity
        ], 3); // Prioridad alta para búsquedas individuales
        
        return [
            'success' => true,
            'search_type' => 'individual',
            'search_term' => $searchTerm,
            'job_id' => $jobId,
            'processed_immediately' => false,
            'message' => 'Búsqueda compleja puesta en cola para procesamiento',
            'estimated_time' => 'Menos de 2 minutos'
        ];
    }
}

/**
 * Crea un nuevo lote de búsqueda
 */
function handleCreateBatch(array $input, array $config, Database $db): array
{
    $filePath = $input['file_path'] ?? '';
    $batchName = trim($input['batch_name'] ?? '');
    $searchConfig = $input['search_config'] ?? [];
    
    if (empty($filePath) || !file_exists($filePath)) {
        throw new Exception('Archivo no encontrado');
    }
    
    if (empty($batchName)) {
        $batchName = 'Lote ' . date('Y-m-d H:i:s');
    }
    
    // Procesar archivo Excel
    $processor = new ExcelProcessor();
    $processingResult = $processor->processFile($filePath, 'search');
    
    if (!$processingResult['success']) {
        throw new Exception('Error procesando archivo: ' . $processingResult['message']);
    }
    
    $searchData = $processingResult['data']['data'];
    $totalRecords = count($searchData);
    
    // Validar límites
    $maxBatchSize = $config['search']['max_batch_size'];
    if ($totalRecords > $maxBatchSize) {
        throw new Exception("El lote excede el límite máximo de {$maxBatchSize} registros");
    }
    
    // Crear lote en base de datos
    $batchId = $db->createSearchBatch([
        'batch_name' => $batchName,
        'original_filename' => basename($filePath),
        'total_records' => $totalRecords,
        'search_config' => $searchConfig
    ]);
    
    // Insertar registros de búsqueda
    $db->insertBulkSearches($batchId, $searchData);
    
    // Crear notificación
    $db->createNotification([
        'type' => 'success',
        'title' => 'Lote Creado',
        'message' => "Lote '{$batchName}' creado con {$totalRecords} registros",
        'batch_id' => $batchId,
        'is_persistent' => true
    ]);
    
    $db->log('INFO', 'search_api', 'Lote de búsqueda creado', [
        'batch_id' => $batchId,
        'batch_name' => $batchName,
        'total_records' => $totalRecords
    ]);
    
    return [
        'success' => true,
        'message' => 'Lote creado exitosamente',
        'batch_id' => $batchId,
        'batch_name' => $batchName,
        'total_records' => $totalRecords,
        'file_stats' => $processingResult['statistics'],
        'ready_for_processing' => true
    ];
}

/**
 * Maneja búsquedas masivas por lotes
 */
function handleBatchSearch(array $input, array $config, Database $db): array
{
    $batchId = $input['batch_id'] ?? '';
    $searchConfig = $input['search_config'] ?? [];
    $includeLocal = $searchConfig['include_local'] ?? true;
    $includeExternal = $searchConfig['include_external'] ?? true;
    $selectedSites = $searchConfig['selected_sites'] ?? [];
    $priority = (int)($input['priority'] ?? 1);
    
    if (empty($batchId)) {
        throw new Exception('batch_id requerido');
    }
    
    // Verificar que el lote existe
    $batchProgress = $db->getBatchProgress($batchId);
    if (empty($batchProgress)) {
        throw new Exception('Lote no encontrado');
    }
    
    if ($batchProgress['status'] === 'processing') {
        throw new Exception('El lote ya está siendo procesado');
    }
    
    $queueManager = new QueueManager();
    $jobIds = [];
    
    // Actualizar estado del lote
    $db->query("UPDATE search_batches SET status = 'processing', started_at = CURRENT_TIMESTAMP WHERE id = ?", [$batchId]);
    
    // Programar búsqueda local si está habilitada
    if ($includeLocal) {
        $localJobId = $queueManager->addJob('batch_local_search', [
            'batch_id' => $batchId,
            'config' => [
                'min_similarity' => $searchConfig['min_similarity'] ?? $config['search']['similarity']['default_threshold'],
                'batch_size' => $config['search']['max_batch_size'],
                'delay_between_searches' => $config['search']['batch_processing_delay']
            ]
        ], $priority);
        
        $jobIds['local_search'] = $localJobId;
    }
    
    // Programar búsqueda externa si está habilitada
    if ($includeExternal && !empty($selectedSites)) {
        $externalJobId = $queueManager->addJob('batch_external_search', [
            'batch_id' => $batchId,
            'selected_sites' => $selectedSites,
            'options' => [
                'batch_size' => min(50, $config['search']['max_batch_size']),
                'delay_between_searches' => $config['scrapers']['rate_limit_delay']
            ]
        ], $priority - 1); // Prioridad ligeramente menor para scrapers
        
        $jobIds['external_search'] = $externalJobId;
    }
    
    // Crear notificación de inicio
    $db->createNotification([
        'type' => 'info',
        'title' => 'Búsqueda Masiva Iniciada',
        'message' => "Procesando lote '{$batchProgress['batch_name']}'",
        'batch_id' => $batchId,
        'is_persistent' => true,
        'auto_dismiss_seconds' => 10
    ]);
    
    $db->log('INFO', 'search_api', 'Búsqueda masiva iniciada', [
        'batch_id' => $batchId,
        'job_ids' => $jobIds,
        'include_local' => $includeLocal,
        'include_external' => $includeExternal,
        'selected_sites' => $selectedSites
    ]);
    
    return [
        'success' => true,
        'message' => 'Búsqueda masiva iniciada',
        'batch_id' => $batchId,
        'job_ids' => $jobIds,
        'processing_config' => [
            'include_local' => $includeLocal,
            'include_external' => $includeExternal,
            'sites_count' => count($selectedSites),
            'total_records' => $batchProgress['total_records']
        ],
        'estimated_completion' => $this->estimateCompletionTime($batchProgress['total_records'], $includeLocal, count($selectedSites))
    ];
}

/**
 * Estima tiempo de completitud
 */
function estimateCompletionTime(int $totalRecords, bool $includeLocal, int $sitesCount): string
{
    $timePerRecord = 0.5; // segundos base por registro
    
    if ($includeLocal) {
        $timePerRecord += 0.2; // tiempo adicional para búsqueda local
    }
    
    if ($sitesCount > 0) {
        $timePerRecord += ($sitesCount * 1.5); // tiempo por sitio externo
    }
    
    $totalSeconds = $totalRecords * $timePerRecord;
    
    if ($totalSeconds < 60) {
        return 'Menos de 1 minuto';
    } elseif ($totalSeconds < 3600) {
        return round($totalSeconds / 60) . ' minutos';
    } else {
        return round($totalSeconds / 3600, 1) . ' horas';
    }
}