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
    /** @var array<string, mixed> */
    private array $config;
    // private QueueManager $queueManager; // Marked as unused by PHPStan
    /** @var array<string, array<string, mixed>> */
    private array $activeSites;
    /** @var array<string, int|float> */
    private array $scraperStats;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/app.php';
        // $this->queueManager = new QueueManager(); // Marked as unused by PHPStan
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
     * @param string $searchTerm
     * @param string[] $selectedSites
     * @param array<string, mixed> $options
     * @return array<string, mixed>
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
     * @param string $batchId
     * @param string[] $selectedSites
     * @param array<string, mixed> $options
     * @return array<string, mixed>
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
     * @param string $searchTerm
     * @param array<int, array<string, mixed>> $sites
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function processScraperGroup(string $searchTerm, array $sites, array $options): array
    {
        /** @var array<int, array<string, mixed>> $results */
        $results = [];
        /** @var array<string, array<string, mixed>> $processes */
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
     * @param string $searchTerm
     * @param array<string, mixed> $site
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function startScraperProcess(string $searchTerm, array $site, array $options): ?array
    {
        /** @var string $scraperType */
        $scraperType = $site['scraper_type'];
        /** @var string $siteName */
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
     * @param string $searchTerm
     * @param array<string, mixed> $site
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function startPuppeteerScraper(string $searchTerm, array $site, array $options): array
    {
        $scraperFile = $this->getScraperFile($site);
        /** @var int $timeout */
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
            $configDataString = json_encode($site['config_data']);
            if ($configDataString === false) {
                throw new Exception("No se pudo codificar config_data a JSON para el sitio {$site['site_name']}");
            }
            $command[] = '--config=' . escapeshellarg($configDataString);
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
     * @param string $searchTerm
     * @param array<string, mixed> $site
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function startAxiosScraper(string $searchTerm, array $site, array $options): array
    {
        $scraperFile = $this->getScraperFile($site);
        /** @var int $timeout */
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
     * @param string $searchTerm
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function createDirectLink(string $searchTerm, array $site): array
    {
        /** @var array<string, mixed> $configData */
        $configData = is_string($site['config_data']) ? json_decode($site['config_data'], true) : ($site['config_data'] ?? []);
        if ($configData === null) { // Handle json_decode failure
            $configData = [];
        }
        $searchUrl = (string)($configData['search_url'] ?? '');

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
     * @param array<string, mixed> $process
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function waitForScraperResult(array $process, array $site): array
    {
        /** @var float $startTime */
        $startTime = $process['start_time'];
        /** @var int $maxTimeoutMs */
        $maxTimeoutMs = ($site['max_timeout_seconds'] ?? 30) * 1000; // Convertir a ms

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
                    if ((microtime(true) - $startWait) * 1000 > $maxTimeoutMs) {
                        proc_terminate($process_handle);
                        throw new Exception("Timeout después de {$maxTimeoutMs}ms");
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
                /** @var array<string,mixed>|null $scraperResult */
                $scraperResult = json_decode($output, true);

                if ($scraperResult !== null && json_last_error() === JSON_ERROR_NONE) {
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
     * @param array<string, mixed> $site
     * @return string
     */
    private function getScraperFile(array $site): string
    {
        /** @var string $category */
        $category = $site['category'];
        /** @var string $siteNameRaw */
        $siteNameRaw = $site['site_name'];
        $siteName = $this->normalizeFileName($siteNameRaw);

        $scraperPath = $this->config['paths']['root'] . "/scrapers/{$category}/{$siteName}.js";

        if (!file_exists($scraperPath)) {
            throw new Exception("Archivo scraper no encontrado: {$scraperPath}");
        }

        return $scraperPath;
    }

    /**
     * Normaliza nombre de archivo
     */
    private function normalizeFileName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]/', '-', $normalized);
        $normalized = $normalized !== null ? preg_replace('/-+/', '-', $normalized) : '';
        $normalized = trim($normalized ?? '', '-');

        return $normalized;
    }

    /**
     * Valida sitios seleccionados
     * @param string[] $selectedSites
     * @return array<int, array<string, mixed>>
     */
    private function validateSelectedSites(array $selectedSites): array
    {
        /** @var array<int, array<string, mixed>> $validSites */
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
     * @param array<string, mixed> $search
     * @param array<int, array<string, mixed>> $sites
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function executeScrapersForSearch(array $search, array $sites, array $options): array
    {
        /** @var string|null $searchTermNull */
        $searchTermNull = $search['full_name'] ?? null;
        /** @var string|null $searchIdNull */
        $searchIdNull = $search['identification'] ?? null;

        // Usar nombre e identificación como términos de búsqueda
        /** @var string[] $searchTerms */
        $searchTerms = array_filter([$searchTermNull, $searchIdNull]);
        /** @var array<int, array<string, mixed>> $allResults */
        $allResults = [];

        foreach ($searchTerms as $term) {
            // No need for empty check here as array_filter already removed them
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
     * @return array<string, mixed>
     */
    private function createErrorResult(string $siteName, string $searchTerm, string $error): array
    {
        /** @var array<string, mixed> $site */
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
     * @param array<string, mixed> $search
     * @param array<int, array<string, mixed>> $sites
     * @param string $error
     * @return array<int, array<string, mixed>>
     */
    private function createErrorResults(array $search, array $sites, string $error): array
    {
        /** @var array<int, array<string, mixed>> $results */
        $results = [];

        foreach ($sites as $site) {
            $results[] = [
                'site_name' => $site['site_name'] ?? 'unknown',
                'site_category' => $site['category'] ?? 'unknown',
                'search_query' => (string)($search['full_name'] ?? ''),
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
     * @return array<int, array<string, mixed>>
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
        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $results;
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
     * @param array<int, array<string,mixed>> $results
     */
    private function updateStats(array $results, float $executionTime): void
    {
        $this->scraperStats['total_searches'] = ($this->scraperStats['total_searches'] ?? 0) + count($results);
        $this->scraperStats['total_execution_time'] = ($this->scraperStats['total_execution_time'] ?? 0.0) + $executionTime;

        foreach ($results as $result) {
            $status = (string)($result['scraper_status'] ?? 'unknown');
            switch ($status) {
                case 'completed':
                    $this->scraperStats['successful_searches'] = ($this->scraperStats['successful_searches'] ?? 0) + 1;
                    if ($result['has_results'] ?? false) {
                        $this->scraperStats['sites_with_results'] = ($this->scraperStats['sites_with_results'] ?? 0) + 1;
                    }
                    break;
                case 'failed':
                    $this->scraperStats['failed_searches'] = ($this->scraperStats['failed_searches'] ?? 0) + 1;
                    break;
                case 'timeout':
                    $this->scraperStats['timeout_searches'] = ($this->scraperStats['timeout_searches'] ?? 0) + 1;
                    break;
                case 'blocked':
                    $this->scraperStats['blocked_searches'] = ($this->scraperStats['blocked_searches'] ?? 0) + 1;
                    break;
            }
        }
    }

    /**
     * Obtiene lista de sitios disponibles
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableSites(): array
    {
        return array_values($this->activeSites);
    }

    /**
     * Obtiene sitios por categoría
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getSitesByCategory(): array
    {
        /** @var array<string, array<int, array<string, mixed>>> $categories */
        $categories = [];

        foreach ($this->activeSites as $site) {
            /** @var string $category */
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
     * @return array<string, int|float>
     */
    public function getScraperStats(): array
    {
        $stats = $this->scraperStats;

        if ($stats['total_searches'] > 0) {
            $stats['success_rate'] = round(((float)$stats['successful_searches'] / (float)$stats['total_searches']) * 100, 2);
            $stats['average_execution_time'] = round((float)$stats['total_execution_time'] / (float)$stats['total_searches'], 2);
        } else {
            $stats['success_rate'] = 0.0;
            $stats['average_execution_time'] = 0.0;
        }

        return $stats;
    }

    /**
     * Actualiza configuración de un sitio
     * @param string $siteName
     * @param array<string, mixed> $config
     * @return bool
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
