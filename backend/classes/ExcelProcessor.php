<?php

namespace ScreeningApp;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Clase ExcelProcessor - Procesamiento inteligente de archivos Excel
 * Extrae automáticamente Identificación y Nombre de cualquier estructura
 */
class ExcelProcessor
{
    private Logger $logger;
    private array $config;
    private Database $db;
    
    // Patrones para identificar columnas de identificación
    private array $identificationPatterns = [
        'cedula', 'cédula', 'cc', 'documento', 'id', 'identificacion', 'identificación',
        'rfc', 'curp', 'nit', 'passport', 'pasaporte', 'dni', 'ci', 'rut', 'ruc',
        'numero', 'número', 'no', 'num', 'code', 'codigo', 'código'
    ];
    
    // Patrones para identificar columnas de nombres
    private array $namePatterns = [
        'nombre', 'names', 'name', 'apellido', 'apellidos', 'razon_social', 'razón_social',
        'razon social', 'razón social', 'empresa', 'company', 'denominacion', 'denominación',
        'full_name', 'fullname', 'complete_name', 'nombre_completo', 'nombre completo',
        'first_name', 'last_name', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'
    ];
    
    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/app.php';
        $this->db = Database::getInstance();
        $this->setupLogger();
    }
    
    /**
     * Configura el logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('excel_processor');
        $logFile = $this->config['logging']['files']['application'] ?? 'logs/application.log';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }
    
    /**
     * Procesa un archivo Excel y extrae los datos automáticamente
     */
    public function processFile(string $filePath, string $fileType = 'search'): array
    {
        $this->logger->info("Iniciando procesamiento de archivo Excel", [
            'file_path' => $filePath,
            'file_type' => $fileType
        ]);
        
        try {
            // Validar archivo
            $this->validateFile($filePath);
            
            // Cargar archivo Excel
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Analizar estructura del archivo
            $structure = $this->analyzeStructure($worksheet);
            
            // Extraer datos
            $extractedData = $this->extractData($worksheet, $structure);
            
            // Validar datos extraídos
            $validatedData = $this->validateExtractedData($extractedData);
            
            // Guardar en base de datos según el tipo
            if ($fileType === 'local_database') {
                $result = $this->saveLocalDatabase($validatedData, basename($filePath));
            } else {
                $result = $this->prepareSearchData($validatedData);
            }
            
            $this->logger->info("Archivo procesado exitosamente", [
                'total_rows' => count($validatedData),
                'valid_rows' => count($result['data'] ?? []),
                'file_type' => $fileType
            ]);
            
            return [
                'success' => true,
                'message' => 'Archivo procesado exitosamente',
                'data' => $result,
                'statistics' => [
                    'total_rows' => count($validatedData),
                    'valid_rows' => count($result['data'] ?? []),
                    'structure_detected' => $structure
                ]
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Error procesando archivo Excel", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error procesando archivo: ' . $e->getMessage(),
                'error_details' => $e->getTrace()
            ];
        }
    }
    
    /**
     * Valida que el archivo sea válido y procesable
     */
    private function validateFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception("El archivo no existe: {$filePath}");
        }
        
        $fileInfo = pathinfo($filePath);
        $allowedExtensions = $this->config['files']['allowed_extensions'] ?? ['xlsx', 'xls', 'csv'];
        
        if (!in_array(strtolower($fileInfo['extension']), $allowedExtensions)) {
            throw new Exception("Extensión de archivo no permitida. Permitidas: " . implode(', ', $allowedExtensions));
        }
        
        $fileSizeMB = filesize($filePath) / (1024 * 1024);
        $maxSizeMB = $this->config['files']['max_size_mb'] ?? 50;
        
        if ($fileSizeMB > $maxSizeMB) {
            throw new Exception("El archivo excede el tamaño máximo permitido de {$maxSizeMB}MB");
        }
    }
    
    /**
     * Analiza la estructura del archivo para identificar columnas relevantes
     */
    private function analyzeStructure(Worksheet $worksheet): array
    {
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        
        $this->logger->info("Analizando estructura del archivo", [
            'rows' => $highestRow,
            'columns' => $highestColumn
        ]);
        
        $structure = [
            'header_row' => 1,
            'data_start_row' => 2,
            'identification_column' => null,
            'name_column' => null,
            'total_rows' => $highestRow,
            'total_columns' => $highestColumn,
            'detected_headers' => []
        ];
        
        // Buscar fila de encabezados analizando las primeras 5 filas
        for ($row = 1; $row <= min(5, $highestRow); $row++) {
            $headers = $this->getRowHeaders($worksheet, $row, $highestColumn);
            
            if ($this->isHeaderRow($headers)) {
                $structure['header_row'] = $row;
                $structure['data_start_row'] = $row + 1;
                $structure['detected_headers'] = $headers;
                break;
            }
        }
        
        // Si no se encontraron encabezados, asumir que la primera fila son datos
        if (empty($structure['detected_headers'])) {
            $structure['header_row'] = null;
            $structure['data_start_row'] = 1;
            // Crear encabezados genéricos
            for ($col = 1; $col <= $worksheet->getHighestColumnIndex() + 1; $col++) {
                $structure['detected_headers'][] = "Columna_{$col}";
            }
        }
        
        // Identificar columnas de identificación y nombre
        $this->identifyRelevantColumns($structure, $worksheet);
        
        return $structure;
    }
    
    /**
     * Obtiene los encabezados de una fila específica
     */
    private function getRowHeaders(Worksheet $worksheet, int $row, string $highestColumn): array
    {
        $headers = [];
        $columnIndex = 1;
        
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $cellValue = $worksheet->getCell($col . $row)->getCalculatedValue();
            $headers[$columnIndex] = trim((string)$cellValue);
            $columnIndex++;
        }
        
        return array_filter($headers); // Remover valores vacíos
    }
    
    /**
     * Determina si una fila contiene encabezados
     */
    private function isHeaderRow(array $headers): bool
    {
        if (empty($headers)) {
            return false;
        }
        
        $textCells = 0;
        $totalCells = count($headers);
        
        foreach ($headers as $header) {
            // Si contiene texto y no es solo números, probablemente es encabezado
            if (!empty($header) && !is_numeric($header) && strlen($header) > 1) {
                $textCells++;
            }
        }
        
        // Si más del 50% de las celdas contienen texto, es probablemente encabezado
        return ($textCells / $totalCells) > 0.5;
    }
    
    /**
     * Identifica las columnas de identificación y nombre
     */
    private function identifyRelevantColumns(array &$structure, Worksheet $worksheet): void
    {
        $headers = $structure['detected_headers'];
        
        // Buscar columna de identificación
        foreach ($headers as $index => $header) {
            $normalizedHeader = $this->normalizeText($header);
            
            foreach ($this->identificationPatterns as $pattern) {
                if (strpos($normalizedHeader, $this->normalizeText($pattern)) !== false) {
                    $structure['identification_column'] = $index;
                    break 2;
                }
            }
        }
        
        // Buscar columna de nombre
        foreach ($headers as $index => $header) {
            $normalizedHeader = $this->normalizeText($header);
            
            foreach ($this->namePatterns as $pattern) {
                if (strpos($normalizedHeader, $this->normalizeText($pattern)) !== false) {
                    $structure['name_column'] = $index;
                    break 2;
                }
            }
        }
        
        // Si no se encontraron por patrones, usar heurística analizando datos
        if ($structure['identification_column'] === null || $structure['name_column'] === null) {
            $this->identifyColumnsByData($structure, $worksheet);
        }
        
        $this->logger->info("Columnas identificadas", [
            'identification_column' => $structure['identification_column'],
            'name_column' => $structure['name_column'],
            'headers' => $headers
        ]);
    }
    
    /**
     * Identifica columnas analizando el contenido de los datos
     */
    private function identifyColumnsByData(array &$structure, Worksheet $worksheet): void
    {
        $dataStartRow = $structure['data_start_row'];
        $sampleRows = min(10, $structure['total_rows'] - $dataStartRow + 1);
        
        $columnAnalysis = [];
        
        // Analizar las primeras filas de datos
        for ($row = $dataStartRow; $row < $dataStartRow + $sampleRows; $row++) {
            $col = 'A';
            $colIndex = 1;
            
            while ($col <= $structure['total_columns']) {
                $cellValue = trim((string)$worksheet->getCell($col . $row)->getCalculatedValue());
                
                if (!empty($cellValue)) {
                    if (!isset($columnAnalysis[$colIndex])) {
                        $columnAnalysis[$colIndex] = [
                            'numeric_count' => 0,
                            'text_count' => 0,
                            'avg_length' => 0,
                            'samples' => []
                        ];
                    }
                    
                    $columnAnalysis[$colIndex]['samples'][] = $cellValue;
                    
                    if (is_numeric($cellValue)) {
                        $columnAnalysis[$colIndex]['numeric_count']++;
                    } else {
                        $columnAnalysis[$colIndex]['text_count']++;
                    }
                }
                
                $col++;
                $colIndex++;
            }
        }
        
        // Determinar columnas basado en el análisis
        foreach ($columnAnalysis as $colIndex => $analysis) {
            $totalSamples = count($analysis['samples']);
            if ($totalSamples === 0) continue;
            
            $avgLength = array_sum(array_map('strlen', $analysis['samples'])) / $totalSamples;
            $numericRatio = $analysis['numeric_count'] / $totalSamples;
            
            // Columna de identificación: principalmente numérica, longitud media
            if ($structure['identification_column'] === null && 
                $numericRatio > 0.7 && 
                $avgLength >= 5 && $avgLength <= 20) {
                $structure['identification_column'] = $colIndex;
            }
            
            // Columna de nombre: principalmente texto, longitud mayor
            if ($structure['name_column'] === null && 
                $numericRatio < 0.3 && 
                $avgLength > 10) {
                $structure['name_column'] = $colIndex;
            }
        }
        
        // Si aún no se encuentran, usar las primeras dos columnas como fallback
        if ($structure['identification_column'] === null) {
            $structure['identification_column'] = 1;
        }
        if ($structure['name_column'] === null) {
            $structure['name_column'] = 2;
        }
    }
    
    /**
     * Extrae los datos del archivo según la estructura identificada
     */
    private function extractData(Worksheet $worksheet, array $structure): array
    {
        $data = [];
        $idCol = $this->getExcelColumn($structure['identification_column']);
        $nameCol = $this->getExcelColumn($structure['name_column']);
        
        $maxRows = $this->config['files']['max_excel_rows'] ?? 10000;
        $endRow = min($structure['total_rows'], $structure['data_start_row'] + $maxRows - 1);
        
        for ($row = $structure['data_start_row']; $row <= $endRow; $row++) {
            $identification = trim((string)$worksheet->getCell($idCol . $row)->getCalculatedValue());
            $fullName = trim((string)$worksheet->getCell($nameCol . $row)->getCalculatedValue());
            
            // Extraer datos adicionales de otras columnas
            $additionalData = [];
            $col = 'A';
            $colIndex = 1;
            
            while ($col <= $structure['total_columns']) {
                if ($colIndex !== $structure['identification_column'] && 
                    $colIndex !== $structure['name_column']) {
                    $cellValue = trim((string)$worksheet->getCell($col . $row)->getCalculatedValue());
                    if (!empty($cellValue)) {
                        $headerName = $structure['detected_headers'][$colIndex] ?? "Columna_{$colIndex}";
                        $additionalData[$headerName] = $cellValue;
                    }
                }
                $col++;
                $colIndex++;
            }
            
            if (!empty($identification) || !empty($fullName)) {
                $data[] = [
                    'identification' => $identification,
                    'full_name' => $fullName,
                    'original_row_data' => $additionalData,
                    'row_number' => $row
                ];
            }
        }
        
        return $data;
    }
    
    /**
     * Convierte un índice numérico a letra de columna Excel
     */
    private function getExcelColumn(int $index): string
    {
        $column = '';
        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)) . $column;
            $index = intval($index / 26);
        }
        return $column;
    }
    
    /**
     * Valida los datos extraídos
     */
    private function validateExtractedData(array $data): array
    {
        $validData = [];
        $errors = [];
        
        foreach ($data as $index => $row) {
            $isValid = true;
            $rowErrors = [];
            
            // Validar identificación
            if (empty(trim($row['identification'])) && empty(trim($row['full_name']))) {
                $isValid = false;
                $rowErrors[] = 'Tanto identificación como nombre están vacíos';
            }
            
            // Normalizar y limpiar datos
            $row['identification'] = $this->cleanIdentification($row['identification']);
            $row['full_name'] = $this->cleanName($row['full_name']);
            
            if ($isValid) {
                $validData[] = $row;
            } else {
                $errors[] = [
                    'row_number' => $row['row_number'],
                    'errors' => $rowErrors
                ];
            }
        }
        
        if (!empty($errors)) {
            $this->logger->warning("Datos con errores encontrados", [
                'total_errors' => count($errors),
                'sample_errors' => array_slice($errors, 0, 5)
            ]);
        }
        
        return $validData;
    }
    
    /**
     * Limpia y normaliza la identificación
     */
    private function cleanIdentification(string $id): string
    {
        // Remover espacios, guiones y caracteres especiales
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', trim($id));
        return strtoupper($cleaned);
    }
    
    /**
     * Limpia y normaliza el nombre
     */
    private function cleanName(string $name): string
    {
        // Normalizar espacios y caracteres especiales
        $cleaned = preg_replace('/\s+/', ' ', trim($name));
        $cleaned = preg_replace('/[^\w\s\.\-]/', '', $cleaned);
        return ucwords(strtolower($cleaned));
    }
    
    /**
     * Normaliza texto para comparaciones
     */
    private function normalizeText(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]/', '', $text);
        return $text;
    }
    
    /**
     * Guarda datos como base de datos local
     */
    private function saveLocalDatabase(array $data, string $sourceName): array
    {
        $saved = 0;
        $duplicates = 0;
        $errors = 0;
        
        foreach ($data as $row) {
            try {
                $sql = "INSERT INTO local_database_records (
                    source_name, identification, full_name, additional_data
                ) VALUES (?, ?, ?, ?)";
                
                $this->db->query($sql, [
                    $sourceName,
                    $row['identification'],
                    $row['full_name'],
                    json_encode($row['original_row_data'])
                ]);
                
                $saved++;
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'record_hash') !== false) {
                    $duplicates++;
                } else {
                    $errors++;
                    $this->logger->error("Error guardando registro local", [
                        'row' => $row,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return [
            'type' => 'local_database',
            'source_name' => $sourceName,
            'total_processed' => count($data),
            'saved' => $saved,
            'duplicates' => $duplicates,
            'errors' => $errors
        ];
    }
    
    /**
     * Prepara datos para búsqueda
     */
    private function prepareSearchData(array $data): array
    {
        return [
            'type' => 'search_data',
            'data' => $data,
            'total_records' => count($data),
            'ready_for_batch' => true
        ];
    }
    
    /**
     * Obtiene estadísticas de un archivo procesado
     */
    public function getFileStatistics(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            return [
                'file_size_mb' => round(filesize($filePath) / (1024 * 1024), 2),
                'total_rows' => $worksheet->getHighestRow(),
                'total_columns' => $worksheet->getHighestColumnIndex() + 1,
                'estimated_processing_time' => $this->estimateProcessingTime($worksheet->getHighestRow())
            ];
            
        } catch (Exception $e) {
            return [
                'error' => 'No se pudo analizar el archivo: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Estima el tiempo de procesamiento
     */
    private function estimateProcessingTime(int $rows): string
    {
        $secondsPerRow = 0.1; // Estimación basada en pruebas
        $totalSeconds = $rows * $secondsPerRow;
        
        if ($totalSeconds < 60) {
            return round($totalSeconds) . ' segundos';
        } elseif ($totalSeconds < 3600) {
            return round($totalSeconds / 60) . ' minutos';
        } else {
            return round($totalSeconds / 3600, 1) . ' horas';
        }
    }
}