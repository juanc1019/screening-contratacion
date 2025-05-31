<?php

namespace ScreeningApp;

use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Clase ScraperManager - Administrador de scrapers externos
 * Maneja la ejecución de los 22 scrapers de sitios externos
 */
class ScraperManager
{
    private Database $db;
    private Logger $logger;
    private array $config;
    private QueueManager $queueManager;
    private array $activeSites;
    private array $scraperStats;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/app.php';
        $this->queueManager = new QueueManager();
        $this->setupLogger();
        $this->loadActiveSites();
        $this->resetStats();
    }
    
    /**
     * Configura el logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('scraper_manager');
        $logFile = $this->config['logging']['files']['scrapers'] ?? 'logs/scrapers.log';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }
    
    /**
     * Carga sitios activos desde la base de datos
     */
    private function loadActiveSites(): void
    {
        $sql = "SELECT * FROM scraper_sites WHERE is_active = true ORDER BY category, site_name";
        $stmt = $this->db->query($sql);
        $sites = $stmt->fetchAll();
        
        $this->activeSites = [];
        foreach ($sites as $site) {
            $this->activeSites[$site['site_name']] = $site;
        }
        
        $this->logger->info("Sitios activos cargados", ['count' => count($this->activeSites)]);
    }
    
    /**
     * Resetea estadísticas
     */
    private function resetStats(): void
    {
        $this->scraperStats = [
            'total_searches' => 0,
            'successful_searches' => 0,
            'failed_searches' => 0,
            'timeout_searches' => 0,
            'blocked_searches' => 0,
            'total_execution_time' => 0,
            'sites_with_results' => 0
        ];
    }
    
    /**
     * Ejecuta búsqueda individual en sitios seleccionados
     */
    public function searchIndividual(string $searchTerm, array $selectedSites = [], array $options = []): array
    {
        $startTime = microtime(true);
        
        $this->logger->info("Iniciando búsqueda individual en scrapers", [
            'search_term' => $searchTerm,
            'selected_sites' => $selectedSites,
            'options' => $options
        ]);
        
        try {
            // Si no se especifican sitios, usar todos los activos
            if (empty($selectedSites)) {
                $selectedSites = array_keys($this->activeSites);
            }
            
            // Validar sitios seleccionados
            $validSites = $this->validateSelectedSites($selectedSites);
            
            if (empty($validSites)) {
                throw new Exception("No hay sitios válidos para procesar");
            }
            
            $results = [];
            $concurrentLimit = $this->config['search']['max_concurrent_scrapers'] ?? 3;
            
            // Dividir sitios en grupos para procesamiento concurrente limitado
            $siteGroups = array_chunk($validSites, $concurrentLimit);
            
            foreach ($siteGroups as $groupIndex => $group) {
                $this->logger->debug("Procesando grupo de sitios", [
                    'group' => $groupIndex + 1,
                    'sites' => array_column($group, 'site_name')
                ]);
                
                $groupResults = $this->processScraperGroup($searchTerm, $group, $options);
                $results = array_merge($results, $groupResults);
                
                // Pausa entre grupos para evitar sobrecarga
                if (count($siteGroups) > 1 && $groupIndex < count($siteGroups) - 1) {
                    $delay = $this->config['scrapers']['rate_limit_delay'] ?? 2000;
                    usleep($delay * 1000); // Convertir a microsegundos
                }
            }
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->updateStats($results, $executionTime);
            
            $this->logger->info("Búsqueda individual completada", [
                'sites_processed' => count($validSites),
                'sites_with_results' => count(array_filter($results, fn($r) => $r['has_results'])),
                'execution_time_ms' => round($executionTime, 2)
            ]);
            
            return [
                'success' => true,
                'results' => $results,
                'metadata' => [
                    'search_term' => $searchTerm,
                    'sites_processed' => count($validSites),
                    'sites_with_results' => count(array_filter($results, fn($r) => $r['has_results'])),
                    'execution_time_ms' => round($executionTime, 2),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Error en búsqueda individual de scrapers", [
                'search_term' => $searchTerm,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Error en scrapers: ' . $e->getMessage(),
                'results' => []
            ];
        }
    }
    
    /**
     * Ejecuta búsquedas masivas para un lote
     */
    public function searchBatch(string $batchId, array $selectedSites = [], array $options = []): array
    {
        $startTime = microtime(true);
        
        $this->logger->info("Iniciando búsqueda masiva en scrapers", [
            'batch_id' => $batchId,
            'selected_sites' => $selectedSites,
            'options' => $options
        ]);
        
        try {
            // Obtener búsquedas pendientes
            $searches = $this->getPendingBatchSearches($batchId, $options['batch_size'] ?? 50);
            
            if (empty($searches)) {
                return [
                    'success' => true,
                    'message' => 'No hay búsquedas pendientes para scrapers',
                    'processed' => 0
                ];
            }
            
            // Validar sitios
            $validSites = $this->validateSelectedSites($selectedSites ?: array_keys($this->activeSites));
            
            $processed = 0;
            $totalResults = 0;
            
            foreach ($searches as $search) {
                try {
                    $this->logger->debug("Procesando búsqueda externa", [
                        'search_id' => $search['id'],
                        'name' => $search['full_name']
                    ]);
                    
                    // Ejecutar scrapers para esta búsqueda
                    $searchResults = $this->executeScrapersForSearch($search, $validSites, $options);
                    
                    // Guardar resultados en base de datos
                    if (!empty($searchResults)) {
                        $this->db->saveExternalResults($search['id'], $searchResults);
                        $totalResults += count($searchResults);
                    }
                    
                    $processed++;
                    
                    // Notificación de progreso cada 5 búsquedas
                    if ($processed % 5 === 0) {
                        $this->createProgressNotification($batchId, $processed, count($searches), 'external');
                    }
                    
                    // Pausa entre búsquedas para no saturar sitios
                    $delay = $options['delay_between_searches'] ?? $this->config['scrapers']['rate_limit_delay'] ?? 2000;
                    if ($delay > 0 && $processed < count($searches)) {
                        usleep($delay * 1000);
                    }
                    
                } catch (Exception $e) {
                    $this->logger->error("Error procesando búsqueda externa", [
                        'search_id' => $search['id'],
                        'error' => $e->getMessage()
                    ]);
                    
                    // Guardar error en resultados
                    $errorResults = $this->createErrorResults($search, $validSites, $e->getMessage());
                    $this->db->saveExternalResults($search['id'], $errorResults);
                }
            }
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info("Lote de scrapers completado", [
                'batch_id' => $batchId,
                'processed' => $processed,
                'total_results' => $totalResults,
                'execution_time_ms' => round($executionTime, 2)
            ]);
            
            return [
                'success' => true,
                'processed' => $processed,
                'total_results' => $totalResults,
                'execution_time_ms' => round($executionTime, 2),
                'has_more' => $this->hasPendingBatchSearches($batchId)
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Error en búsqueda masiva de scrapers", [
                'batch_id' => $batchId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Error en scrapers masivos: ' . $e->getMessage(),
                'processed' => 0
            ];
        }
    }
    
    /**
     * Procesa un grupo de scrapers de forma concurrente
     */
    private function processScraperGroup(string $searchTerm, array $sites, array $options): array
    {
        $results = [];
        $processes = [];
        
        // Iniciar procesos de scrapers
        foreach ($sites as $site) {
            $process = $this->startScraperProcess($searchTerm, $site, $options);
            if ($process !== null) {
                $processes[$site['site_name']] = $process;
            }
        }
        
        // Esperar y recolectar resultados
        foreach ($processes as $siteName => $process) {
            try {
                $result = $this->waitForScraperResult($process, $this->activeSites[$siteName]);
                $results[] = $result;
            } catch (Exception $e) {
                $this->logger->error("Error ejecutando scraper", [
                    'site' => $siteName,
                    'error' => $e->getMessage()
                ]);
                
                $results[] = $this->createErrorResult($siteName, $searchTerm, $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Inicia proceso de scraper
     */
    private function startScraperProcess(string $searchTerm, array $site, array $options): ?array
    {
        $scraperType = $site['scraper_type'];
        $siteName = $site['site_name'];
        
        try {
            switch ($scraperType) {
                case 'puppeteer':
                    return $this->startPuppeteerScraper($searchTerm, $site, $options);
                    
                case 'axios':
                    return $this->startAxiosScraper($searchTerm, $site, $options);
                    
                case 'direct_link':
                    return $this->createDirectLink($searchTerm, $site);
                    
                default:
                    throw new Exception("Tipo de scraper no soportado: {$scraperType}");
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error iniciando scraper", [
                'site' => $siteName,
                'type' => $scraperType,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Inicia scraper con Puppeteer
     */
    private function startPuppeteerScraper(string $searchTerm, array $site, array $options): array
    {
        $scraperFile = $this->getScraperFile($site);
        $timeout = $site['max_timeout_seconds'] ?? 30;
        
        $command = [
            $this->config['scrapers']['node_path'] ?? 'node',
            $scraperFile,
            '--search-term=' . escapeshellarg($searchTerm),
            '--timeout=' . $timeout,
            '--headless=' . ($this->config['scrapers']['puppeteer']['headless'] ? 'true' : 'false')
        ];
        
        // Agregar configuración adicional
        if (!empty($site['config_data'])) {
            $command[] = '--config=' . escapeshellarg(json_encode($site['config_data']));
        }
        
        return [
            'type' => 'puppeteer',
            'command' => implode(' ', $command),
            'site' => $site,
            'start_time' => microtime(true),
            'search_term' => $searchTerm
        ];
    }
    
    /**
     * Inicia scraper con Axios
     */
    private function startAxiosScraper(string $searchTerm, array $site, array $options): array
    {
        $scraperFile = $this->getScraperFile($site);
        $timeout = $site['max_timeout_seconds'] ?? 30;
        
        $command = [
            $this->config['scrapers']['node_path'] ?? 'node',
            $scraperFile,
            '--search-term=' . escapeshellarg($searchTerm),
            '--timeout=' . $timeout
        ];
        
        return [
            'type' => 'axios',
            'command' => implode(' ', $command),
            'site' => $site,
            'start_time' => microtime(true),
            'search_term' => $searchTerm
        ];
    }
    
    /**
     * Crea enlace directo (para sitios como Google)
     */
    private function createDirectLink(string $searchTerm, array $site): array
    {
        $config = $site['config_data'] ? json_decode($site['config_data'], true) : [];
        $searchUrl = $config['search_url'] ?? '';
        
        if (empty($searchUrl)) {
            throw new Exception("URL de búsqueda no configurada para {$site['site_name']}");
        }
        
        $directLink = str_replace('{TERM}', urlencode($searchTerm), $searchUrl);
        
        return [
            'type' => 'direct_link',
            'direct_link' => $directLink,
            'site' => $site,
            'start_time' => microtime(true),
            'search_term' => $searchTerm,
            'completed' => true
        ];
    }
    
    /**
     * Espera y procesa resultado de scraper
     */
    private function waitForScraperResult(array $process, array $site): array
    {
        $startTime = $process['start_time'];
        $maxTimeout = ($site['max_timeout_seconds'] ?? 30) * 1000; // Convertir a ms
        
        if ($process['type'] === 'direct_link') {
            return [
                'site_name' => $site['site_name'],
                'site_category' => $site['category'],
                'search_query' => $process['search_term'],
                'has_results' => true,
                'results_count' => 1,
                'results_data' => [
                    'type' => 'direct_link',
                    'url' => $process['direct_link'],
                    'message' => 'Enlace directo generado'
                ],
                'scraper_status' => 'completed',
                'direct_link' => $process['direct_link'],
                'execution_time' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
        
        // Para scrapers que requieren ejecución
        $output = '';
        $error = '';
        $exitCode = 0;
        
        try {
            // Ejecutar comando con timeout
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];
            
            $process_handle = proc_open($process['command'], $descriptorspec, $pipes);
            
            if (is_resource($process_handle)) {
                // Cerrar stdin
                fclose($pipes[0]);
                
                // Leer stdout y stderr con timeout
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                
                $startWait = microtime(true);
                while (proc_get_status($process_handle)['running']) {
                    $output .= fread($pipes[1], 8192);
                    $error .= fread($pipes[2], 8192);
                    
                    // Verificar timeout
                    if ((microtime(true) - $startWait) * 1000 > $maxTimeout) {
                        proc_terminate($process_handle);
                        throw new Exception("Timeout después de {$maxTimeout}ms");
                    }
                    
                    usleep(100000); // 100ms
                }
                
                // Leer salida restante
                $output .= stream_get_contents($pipes[1]);
                $error .= stream_get_contents($pipes[2]);
                
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                $exitCode = proc_close($process_handle);
            }
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Procesar resultado
            if ($exitCode === 0 && !empty($output)) {
                $scraperResult = json_decode($output, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    return [
                        'site_name' => $site['site_name'],
                        'site_category' => $site['category'],
                        'search_query' => $process['search_term'],
                        'has_results' => $scraperResult['has_results'] ?? false,
                        'results_count' => $scraperResult['results_count'] ?? 0,
                        'results_data' => $scraperResult['data'] ?? [],
                        'scraper_status' => 'completed',
                        'direct_link' => $scraperResult['direct_link'] ?? null,
                        'execution_time' => $executionTime
                    ];
                }
            }
            
            // Si llegamos aquí, hubo un error
            throw new Exception("Scraper falló: " . ($error ?: 'Salida inválida'));
            
        } catch (Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'site_name' => $site['site_name'],
                'site_category' => $site['category'],
                'search_query' => $process['search_term'],
                'has_results' => false,
                'results_count' => 0,
                'results_data' => [],
                'scraper_status' => strpos($e->getMessage(), 'Timeout') !== false ? 'timeout' : 'failed',
                'execution_time' => $executionTime,
                'error_details' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene ruta del archivo scraper
     */
    private function getScraperFile(array $site): string
    {
        $category = $site['category'];
        $siteName = $this->normalizeFileName($site['site_name']);
        
        $scraperPath = $this->config['paths']['root'] . "/scrapers/{$category}/{$siteName}.js";
        
        if (!file_exists($scraperPath)) {
            throw new Exception("Archivo scraper no encontrado: {$scraperPath}");
        }
        
        return $scraperPath;
    }
    
    /**
     * Normaliza nombre de archivo
     */
    private function normalizeFileName(string $name): string
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]/', '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized);
        $normalized = trim($normalized, '-');
        
        return $normalized;
    }
    
    /**
     * Valida sitios seleccionados
     */
    private function validateSelectedSites(array $selectedSites): array
    {
        $validSites = [];
        
        foreach ($selectedSites as $siteName) {
            if (isset($this->activeSites[$siteName])) {
                $validSites[] = $this->activeSites[$siteName];
            } else {
                $this->logger->warning("Sitio no válido o inactivo", ['site' => $siteName]);
            }
        }
        
        return $validSites;
    }
    
    /**
     * Ejecuta scrapers para una búsqueda específica
     */
    private function executeScrapersForSearch(array $search, array $sites, array $options): array
    {
        $searchTerm = $search['full_name'];
        $searchId = $search['identification'];
        
        // Usar nombre e identificación como términos de búsqueda
        $searchTerms = array_filter([$searchTerm, $searchId]);
        $allResults = [];
        
        foreach ($searchTerms as $term) {
            if (empty($term)) continue;
            
            $termResults = $this->processScraperGroup($term, $sites, $options);
            $allResults = array_merge($allResults, $termResults);
        }
        
        // Eliminar duplicados por sitio
        $uniqueResults = [];
        $processed = [];
        
        foreach ($allResults as $result) {
            $siteKey = $result['site_name'];
            if (!isset($processed[$siteKey])) {
                $uniqueResults[] = $result;
                $processed[$siteKey] = true;
            }
        }
        
        return $uniqueResults;
    }
    
    /**
     * Crea resultados de error
     */
    private function createErrorResult(string $siteName, string $searchTerm, string $error): array
    {
        $site = $this->activeSites[$siteName] ?? [];
        
        return [
            'site_name' => $siteName,
            'site_category' => $site['category'] ?? 'unknown',
            'search_query' => $searchTerm,
            'has_results' => false,
            'results_count' => 0,
            'results_data' => [],
            'scraper_status' => 'failed',
            'execution_time' => 0,
            'error_details' => $error
        ];
    }
    
    /**
     * Crea múltiples resultados de error para una búsqueda
     */
    private function createErrorResults(array $search, array $sites, string $error): array
    {
        $results = [];
        
        foreach ($sites as $site) {
            $results[] = [
                'site_name' => $site['site_name'],
                'site_category' => $site['category'],
                'search_query' => $search['full_name'],
                'has_results' => false,
                'results_count' => 0,
                'results_data' => [],
                'scraper_status' => 'failed',
                'execution_time' => 0,
                'error_details' => $error
            ];
        }
        
        return $results;
    }
    
    /**
     * Obtiene búsquedas pendientes para scrapers
     */
    private function getPendingBatchSearches(string $batchId, int $limit): array
    {
        $sql = "SELECT id, identification, full_name 
                FROM bulk_searches 
                WHERE batch_id = ? AND status = 'completed'
                AND id NOT IN (
                    SELECT DISTINCT bulk_search_id 
                    FROM external_results 
                    WHERE bulk_search_id IS NOT NULL
                )
                ORDER BY created_at ASC
                LIMIT ?";
        
        $stmt = $this->db->query($sql, [$batchId, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Verifica si hay búsquedas pendientes para scrapers
     */
    private function hasPendingBatchSearches(string $batchId): bool
    {
        $sql = "SELECT COUNT(*) as pending 
                FROM bulk_searches 
                WHERE batch_id = ? AND status = 'completed'
                AND id NOT IN (
                    SELECT DISTINCT bulk_search_id 
                    FROM external_results 
                    WHERE bulk_search_id IS NOT NULL
                )";
        
        $stmt = $this->db->query($sql, [$batchId]);
        $result = $stmt->fetch();
        
        return (int)$result['pending'] > 0;
    }
    
    /**
     * Crea notificación de progreso
     */
    private function createProgressNotification(string $batchId, int $current, int $total, string $type): void
    {
        $percentage = round(($current / $total) * 100, 1);
        
        $this->db->createNotification([
            'type' => 'progress',
            'title' => 'Búsqueda Externa en Progreso',
            'message' => "Procesados {$current} de {$total} registros en scrapers ({$percentage}%)",
            'progress_current' => $current,
            'progress_total' => $total,
            'batch_id' => $batchId,
            'is_persistent' => false,
            'auto_dismiss_seconds' => 3
        ]);
    }
    
    /**
     * Actualiza estadísticas
     */
    private function updateStats(array $results, float $executionTime): void
    {
        $this->scraperStats['total_searches'] += count($results);
        $this->scraperStats['total_execution_time'] += $executionTime;
        
        foreach ($results as $result) {
            switch ($result['scraper_status']) {
                case 'completed':
                    $this->scraperStats['successful_searches']++;
                    if ($result['has_results']) {
                        $this->scraperStats['sites_with_results']++;
                    }
                    break;
                case 'failed':
                    $this->scraperStats['failed_searches']++;
                    break;
                case 'timeout':
                    $this->scraperStats['timeout_searches']++;
                    break;
                case 'blocked':
                    $this->scraperStats['blocked_searches']++;
                    break;
            }
        }
    }
    
    /**
     * Obtiene lista de sitios disponibles
     */
    public function getAvailableSites(): array
    {
        return array_values($this->activeSites);
    }
    
    /**
     * Obtiene sitios por categoría
     */
    public function getSitesByCategory(): array
    {
        $categories = [];
        
        foreach ($this->activeSites as $site) {
            $category = $site['category'];
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            $categories[$category][] = $site;
        }
        
        return $categories;
    }
    
    /**
     * Obtiene estadísticas de scrapers
     */
    public function getScraperStats(): array
    {
        $stats = $this->scraperStats;
        
        if ($stats['total_searches'] > 0) {
            $stats['success_rate'] = round(($stats['successful_searches'] / $stats['total_searches']) * 100, 2);
            $stats['average_execution_time'] = round($stats['total_execution_time'] / $stats['total_searches'], 2);
        } else {
            $stats['success_rate'] = 0;
            $stats['average_execution_time'] = 0;
        }
        
        return $stats;
    }
    
    /**
     * Actualiza configuración de un sitio
     */
    public function updateSiteConfig(string $siteName, array $config): bool
    {
        try {
            $sql = "UPDATE scraper_sites SET config_data = ?, updated_at = CURRENT_TIMESTAMP WHERE site_name = ?";
            $stmt = $this->db->query($sql, [json_encode($config), $siteName]);
            
            if ($stmt->rowCount() > 0) {
                // Recargar sitios activos
                $this->loadActiveSites();
                
                $this->logger->info("Configuración de sitio actualizada", [
                    'site_name' => $siteName,
                    'config' => $config
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logger->error("Error actualizando configuración de sitio", [
                'site_name' => $siteName,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Habilita o deshabilita un sitio
     */
    public function toggleSite(string $siteName, bool $isActive): bool
    {
        try {
            $sql = "UPDATE scraper_sites SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE site_name = ?";
            $stmt = $this->db->query($sql, [$isActive, $siteName]);
            
            if ($stmt->rowCount() > 0) {
                $this->loadActiveSites();
                
                $this->logger->info("Estado de sitio actualizado", [
                    'site_name' => $siteName,
                    'is_active' => $isActive
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logger->error("Error actualizando estado de sitio", [
                'site_name' => $siteName,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}