<?php

namespace ScreeningApp;

use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Clase SearchEngine - Motor de búsquedas locales con similitud Levenshtein
 * Maneja búsquedas individuales y masivas en base de datos local
 */
class SearchEngine
{
    private Database $db;
    private Logger $logger;
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, int|float> */
    private array $searchStats;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/app.php';
        $this->setupLogger();
        $this->resetStats();
    }

    /**
     * Configura el logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('search_engine');
        $logFile = $this->config['logging']['files']['application'] ?? 'logs/application.log';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }

    /**
     * Resetea las estadísticas de búsqueda
     */
    private function resetStats(): void
    {
        $this->searchStats = [
            'searches_performed' => 0,
            'total_matches_found' => 0,
            'average_similarity' => 0.0,
            'execution_time_ms' => 0.0,
            'cache_hits' => 0
        ];
    }

    /**
     * Realiza búsqueda individual por similitud
     * @param string $searchTerm
     * @param string $searchType
     * @param float|null $minSimilarity
     * @param int $maxResults
     * @return array<string, mixed>
     */
    public function searchIndividual(
        string $searchTerm,
        string $searchType = 'name',
        float $minSimilarity = null,
        int $maxResults = 50
    ): array {
        $startTime = microtime(true);

        // Usar configuración por defecto si no se especifica
        /** @var float $minSimilarityThreshold */
        $minSimilarityThreshold = $this->config['search']['similarity']['default_threshold'] ?? 70.0;
        $minSimilarity = $minSimilarity ?? $minSimilarityThreshold;

        $this->logger->info("Iniciando búsqueda individual", [
            'search_term' => $searchTerm,
            'search_type' => $searchType,
            'min_similarity' => $minSimilarity,
            'max_results' => $maxResults
        ]);

        try {
            $results = [];

            switch ($searchType) {
                case 'name':
                    $results = $this->searchByName($searchTerm, null, $minSimilarity, $maxResults);
                    break;
                case 'identification':
                    $results = $this->searchByIdentification($searchTerm, $minSimilarity, $maxResults);
                    break;
                case 'both':
                    // Intentar como nombre primero, luego como identificación
                    $results = $this->searchByName($searchTerm, null, $minSimilarity, $maxResults);
                    if (empty($results)) {
                        $idResults = $this->searchByIdentification($searchTerm, $minSimilarity, $maxResults);
                        $results = array_merge($results, $idResults);
                    }
                    break;
                default:
                    throw new Exception("Tipo de búsqueda no válido: {$searchType}");
            }

            $executionTime = (microtime(true) - $startTime) * 1000;

            $this->updateStats([
                'searches_performed' => 1,
                'total_matches_found' => count($results),
                'execution_time_ms' => $executionTime
            ]);

            $this->logger->info("Búsqueda individual completada", [
                'results_count' => count($results),
                'execution_time_ms' => round($executionTime, 2)
            ]);

            return [
                'success' => true,
                'results' => $results,
                'metadata' => [
                    'search_term' => $searchTerm,
                    'search_type' => $searchType,
                    'min_similarity' => $minSimilarity,
                    'results_count' => count($results),
                    'execution_time_ms' => round($executionTime, 2),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error("Error en búsqueda individual", [
                'search_term' => $searchTerm,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Error en búsqueda: ' . $e->getMessage(),
                'results' => [],
                'metadata' => [
                    'search_term' => $searchTerm,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000
                ]
            ];
        }
    }

    /**
     * Realiza búsqueda masiva para un lote
     * @param string $batchId
     * @param array<string, mixed> $searchConfig
     * @return array<string, mixed>
     */
    public function searchBatch(string $batchId, array $searchConfig = []): array
    {
        $startTime = microtime(true);

        $this->logger->info("Iniciando búsqueda masiva", [
            'batch_id' => $batchId,
            'config' => $searchConfig
        ]);

        try {
            // Obtener configuración de búsqueda
            $config = array_merge($this->getDefaultBatchConfig(), $searchConfig);

            // Obtener registros pendientes del lote
            $searches = $this->getPendingSearches($batchId, $config['batch_size']);

            if (empty($searches)) {
                return [
                    'success' => true,
                    'message' => 'No hay búsquedas pendientes',
                    'processed' => 0
                ];
            }

            $processed = 0;
            $totalMatches = 0;

            foreach ($searches as $search) {
                try {
                    // Marcar como procesando
                    $this->db->updateSearchStatus($search['id'], 'processing');

                    // Realizar búsqueda local
                    $results = $this->searchByName(
                        $search['full_name'],
                        $search['identification'],
                        $config['min_similarity'],
                        $config['max_results_per_search']
                    );

                    // Guardar resultados
                    if (!empty($results)) {
                        $this->db->saveLocalResults($search['id'], $results);
                        $totalMatches += count($results);
                    }

                    // Marcar como completado
                    $this->db->updateSearchStatus($search['id'], 'completed');
                    $processed++;

                    // Crear notificación de progreso cada 10 registros
                    if ($processed % 10 === 0) {
                        $this->createProgressNotification($batchId, $processed, count($searches));
                    }

                    // Pausa para no saturar el sistema
                    if ($config['delay_between_searches'] > 0) {
                        usleep($config['delay_between_searches'] * 1000);
                    }
                } catch (Exception $e) {
                    $this->logger->error("Error procesando búsqueda", [
                        'search_id' => $search['id'],
                        'error' => $e->getMessage()
                    ]);

                    $this->db->updateSearchStatus($search['id'], 'error', $e->getMessage());
                }
            }

            $executionTime = (microtime(true) - $startTime) * 1000;

            $this->logger->info("Lote de búsqueda completado", [
                'batch_id' => $batchId,
                'processed' => $processed,
                'total_matches' => $totalMatches,
                'execution_time_ms' => round($executionTime, 2)
            ]);

            return [
                'success' => true,
                'processed' => $processed,
                'total_matches' => $totalMatches,
                'execution_time_ms' => round($executionTime, 2),
                'has_more' => $this->hasPendingSearches($batchId)
            ];
        } catch (Exception $e) {
            $this->logger->error("Error en búsqueda masiva", [
                'batch_id' => $batchId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Error en búsqueda masiva: ' . $e->getMessage(),
                'processed' => 0
            ];
        }
    }

    /**
     * Búsqueda por nombre con similitud
     * @param string $name
     * @param string|null $identification
     * @param float $minSimilarity
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function searchByName(
        string $name,
        string $identification = null,
        float $minSimilarity = 70.0,
        int $limit = 50
    ): array {
        // Limpiar y normalizar entrada
        $name = $this->cleanSearchTerm($name);
        $identification = $identification !== null ? $this->cleanSearchTerm($identification) : null;

        if (empty($name)) {
            return [];
        }

        // Usar función PostgreSQL optimizada
        $results = $this->db->searchBySimilarity($name, $identification, $minSimilarity, $limit);

        // Procesar y enriquecer resultados
        return $this->processSearchResults($results, $name, $identification);
    }

    /**
     * Búsqueda por identificación
     * @param string $identification
     * @param float $minSimilarity
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function searchByIdentification(string $identification, float $minSimilarity = 80.0, int $limit = 50): array
    {
        $identification = $this->cleanSearchTerm($identification);

        if (empty($identification)) {
            return [];
        }

        // Búsqueda exacta primero
        $sql = "SELECT *, 100.0 as name_similarity, 100.0 as id_similarity, 100.0 as combined_score
                FROM local_database_records 
                WHERE UPPER(identification) = UPPER(?) AND is_active = true
                LIMIT ?";

        $stmt = $this->db->query($sql, [$identification, $limit]);
        /** @var array<int, array<string, mixed>> $exactResults */
        $exactResults = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Si no hay resultados exactos, buscar por similitud
        if (empty($exactResults) && $minSimilarity < 100.0) { // Ensure float comparison
            /** @var array<int, array<string, mixed>> $results */
            $results = $this->db->searchBySimilarity('', $identification, $minSimilarity, $limit);
            return $this->processSearchResults($results, '', $identification);
        }

        return $this->processSearchResults($exactResults, '', $identification);
    }

    /**
     * Procesa y enriquece los resultados de búsqueda
     * @param array<int, array<string, mixed>> $results
     * @param string $searchName
     * @param string|null $searchId
     * @return array<int, array<string, mixed>>
     */
    private function processSearchResults(array $results, string $searchName = '', ?string $searchId = ''): array
    {
        /** @var array<int, array<string, mixed>> $processed */
        $processed = [];

        foreach ($results as $result) {
            $processed[] = [
                'record_id' => $result['record_id'] ?? ($result['id'] ?? null),
                'identification' => $result['identification'] ?? null,
                'full_name' => $result['full_name'] ?? null,
                'source_name' => $result['source_name'] ?? null,
                'similarity_scores' => [
                    'name_similarity' => (float)($result['name_similarity'] ?? 0),
                    'id_similarity' => (float)($result['id_similarity'] ?? 0),
                    'combined_score' => (float)($result['combined_score'] ?? 0)
                ],
                'match_type' => $this->determineMatchType($result),
                'confidence_level' => $this->calculateConfidenceLevel($result),
                'additional_data' => json_decode($result['additional_data'] ?? '{}', true),
                'match_details' => [
                    'searched_name' => $searchName,
                    'searched_id' => $searchId,
                    'found_name' => $result['full_name'],
                    'found_id' => $result['identification']
                ]
            ];
        }

        // Ordenar por score combinado descendente
        usort($processed, function ($a, $b) {
            return $b['similarity_scores']['combined_score'] <=> $a['similarity_scores']['combined_score'];
        });

        return $processed;
    }

    /**
     * Determina el tipo de coincidencia
     * @param array<string, mixed> $result
     * @return string
     */
    private function determineMatchType(array $result): string
    {
        $nameScore = (float)($result['name_similarity'] ?? 0.0);
        $idScore = (float)($result['id_similarity'] ?? 0.0);

        if (abs($nameScore - 100.0) < 0.001 && abs($idScore - 100.0) < 0.001) {
            return 'exact';
        } elseif ($nameScore >= 95.0 || $idScore >= 95.0) {
            return 'high_similarity';
        } elseif ($nameScore >= 80.0 || $idScore >= 80.0) {
            return 'medium_similarity';
        } else {
            return 'low_similarity';
        }
    }

    /**
     * Calcula el nivel de confianza
     * @param array<string, mixed> $result
     * @return string
     */
    private function calculateConfidenceLevel(array $result): string
    {
        $combinedScore = (float)($result['combined_score'] ?? 0.0);

        if ($combinedScore >= 95.0) {
            return 'very_high';
        } elseif ($combinedScore >= 85.0) {
            return 'high';
        } elseif ($combinedScore >= 70.0) {
            return 'medium';
        } elseif ($combinedScore >= 50.0) {
            return 'low';
        } else {
            return 'very_low';
        }
    }

    /**
     * Limpia término de búsqueda
     */
    private function cleanSearchTerm(?string $term): string
    {
        if ($term === null) {
            return '';
        }
        // Remover caracteres especiales y normalizar
        $cleaned = trim($term);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        /** @var string $cleaned */
        $cleaned = preg_replace('/[^\w\s\.\-ñÑáéíóúÁÉÍÓÚüÜ]/u', '', $cleaned ?? '');

        return $cleaned;
    }

    /**
     * Obtiene configuración por defecto para búsquedas masivas
     * @return array<string, mixed>
     */
    private function getDefaultBatchConfig(): array
    {
        return [
            'batch_size' => $this->config['search']['max_batch_size'] ?? 100,
            'min_similarity' => $this->config['search']['similarity']['default_threshold'] ?? 70.0,
            'max_results_per_search' => 10,
            'delay_between_searches' => $this->config['search']['batch_processing_delay'] ?? 100,
            'enable_fuzzy_search' => true,
            'include_partial_matches' => true
        ];
    }

    /**
     * Obtiene búsquedas pendientes de un lote
     * @param string $batchId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function getPendingSearches(string $batchId, int $limit): array
    {
        $sql = "SELECT id, identification, full_name, original_row_data 
                FROM bulk_searches 
                WHERE batch_id = ? AND status = 'pending'
                ORDER BY priority DESC, created_at ASC
                LIMIT ?";

        $stmt = $this->db->query($sql, [$batchId, $limit]);
        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $results;
    }

    /**
     * Verifica si hay búsquedas pendientes
     */
    private function hasPendingSearches(string $batchId): bool
    {
        $sql = "SELECT COUNT(*) as pending FROM bulk_searches WHERE batch_id = ? AND status = 'pending'";
        $stmt = $this->db->query($sql, [$batchId]);
        /** @var array{pending: int|string}|false $result */
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (int)($result['pending'] ?? 0) > 0;
    }

    /**
     * Crea notificación de progreso
     */
    private function createProgressNotification(string $batchId, int $current, int $total): void
    {
        $percentage = round(($current / $total) * 100, 1);

        $this->db->createNotification([
            'type' => 'progress',
            'title' => 'Búsqueda Local en Progreso',
            'message' => "Procesados {$current} de {$total} registros ({$percentage}%)",
            'progress_current' => $current,
            'progress_total' => $total,
            'batch_id' => $batchId,
            'is_persistent' => false,
            'auto_dismiss_seconds' => 3
        ]);
    }

    /**
     * Actualiza estadísticas internas
     * @param array<string, int|float> $newStats
     */
    private function updateStats(array $newStats): void
    {
        foreach ($newStats as $key => $value) {
            if (isset($this->searchStats[$key])) {
                // Ensure both are numeric before arithmetic operations
                if (is_numeric($value)) { // Check only $value, $this->searchStats[$key] is already number by initialization
                    $this->searchStats[$key] = (float)($this->searchStats[$key] ?? 0.0) + (float)$value;
                }
            }
        }
    }

    /**
     * Busca registros similares para detección de duplicados
     * @param string $name
     * @param string $identification
     * @param float $threshold
     * @return array<int, array<string, mixed>>
     */
    public function findSimilarRecords(string $name, string $identification, float $threshold = 90.0): array
    {
        $this->logger->info("Buscando registros similares para detección de duplicados", [
            'name' => $name,
            'identification' => $identification,
            'threshold' => $threshold
        ]);

        $results = $this->searchByName($name, $identification, $threshold, 20);

        // Filtrar solo registros con alta similitud
        return array_filter($results, function ($record) use ($threshold) {
            return $record['similarity_scores']['combined_score'] >= $threshold;
        });
    }

    /**
     * Realiza búsqueda avanzada con múltiples criterios
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function advancedSearch(array $criteria): array
    {
        $startTime = microtime(true);

        $this->logger->info("Iniciando búsqueda avanzada", ['criteria' => $criteria]);

        try {
            $whereConditions = [];
            $params = [];
            $paramIndex = 1;

            // Construir consulta dinámica
            if (!empty($criteria['name'])) {
                $whereConditions[] = "calculate_similarity(full_name, $$paramIndex) >= ?";
                $params[] = $criteria['name'];
                $params[] = $criteria['name_similarity'] ?? 70.0;
                $paramIndex += 2;
            }

            if (!empty($criteria['identification'])) {
                $whereConditions[] = "calculate_similarity(identification, $$paramIndex) >= ?";
                $params[] = $criteria['identification'];
                $params[] = $criteria['id_similarity'] ?? 80.0;
                $paramIndex += 2;
            }

            if (!empty($criteria['source_name'])) {
                $whereConditions[] = "source_name ILIKE ?";
                $params[] = '%' . $criteria['source_name'] . '%';
            }

            if (empty($whereConditions)) {
                throw new Exception("Al menos un criterio de búsqueda es requerido");
            }

            $sql = "SELECT *, 
                    CASE WHEN ? IS NOT NULL AND ? != '' THEN calculate_similarity(full_name, ?) ELSE 0 END as name_similarity,
                    CASE WHEN ? IS NOT NULL AND ? != '' THEN calculate_similarity(identification, ?) ELSE 0 END as id_similarity
                    FROM local_database_records 
                    WHERE is_active = true AND (" . implode(' OR ', $whereConditions) . ")
                    ORDER BY 
                        GREATEST(
                            CASE WHEN ? IS NOT NULL AND ? != '' THEN calculate_similarity(full_name, ?) ELSE 0 END,
                            CASE WHEN ? IS NOT NULL AND ? != '' THEN calculate_similarity(identification, ?) ELSE 0 END
                        ) DESC
                    LIMIT ?";

            // Preparar parámetros para la consulta completa
            /** @var array<int, mixed> $finalParams */
            $finalParams = [
                $criteria['name'] ?? null, // For CASE WHEN ? IS NOT NULL
                $criteria['name'] ?? '',   // For != ''
                $criteria['name'] ?? '',   // For calculate_similarity
                $criteria['identification'] ?? null, // For CASE WHEN ? IS NOT NULL
                $criteria['identification'] ?? '',   // For != ''
                $criteria['identification'] ?? ''    // For calculate_similarity
            ];
            $finalParams = array_merge($finalParams, $params); // $params already built for WHERE
            $finalParams[] = $criteria['name'] ?? null; // For ORDER BY GREATEST
            $finalParams[] = $criteria['name'] ?? '';
            $finalParams[] = $criteria['name'] ?? '';
            $finalParams[] = $criteria['identification'] ?? null;
            $finalParams[] = $criteria['identification'] ?? '';
            $finalParams[] = $criteria['identification'] ?? '';
            $finalParams[] = (int)($criteria['limit'] ?? 50);

            $stmt = $this->db->query($sql, $finalParams);
            /** @var array<int, array<string, mixed>> $results */
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $processed = $this->processSearchResults(
                $results,
                (string)($criteria['name'] ?? ''),
                ($criteria['identification'] ?? null) === null ? null : (string)$criteria['identification']
            );

            $executionTime = (microtime(true) - $startTime) * 1000;

            return [
                'success' => true,
                'results' => $processed,
                'metadata' => [
                    'criteria' => $criteria,
                    'results_count' => count($processed),
                    'execution_time_ms' => round($executionTime, 2)
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error("Error en búsqueda avanzada", [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Error en búsqueda avanzada: ' . $e->getMessage(),
                'results' => []
            ];
        }
    }

    /**
     * Obtiene estadísticas de búsqueda
     * @return array<string, int|float>
     */
    public function getSearchStats(): array
    {
        $searchesPerformed = (int)($this->searchStats['searches_performed'] ?? 0);
        $cacheHits = (int)($this->searchStats['cache_hits'] ?? 0);
        $executionTimeMs = (float)($this->searchStats['execution_time_ms'] ?? 0.0);

        return array_merge($this->searchStats, [
            'cache_hit_ratio' => $searchesPerformed > 0
                ? round(($cacheHits / $searchesPerformed) * 100, 2)
                : 0.0,
            'average_execution_time_ms' => $searchesPerformed > 0
                ? round($executionTimeMs / $searchesPerformed, 2)
                : 0.0
        ]);
    }

    /**
     * Limpia cache y resetea estadísticas
     */
    public function clearCache(): void
    {
        $this->resetStats();
        $this->logger->info("Cache de búsqueda limpiado y estadísticas reseteadas");
    }
}
