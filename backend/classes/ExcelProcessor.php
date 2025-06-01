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
    /** @var array<string, mixed> */
    private array $config;
    private Database $db;

    // Patrones para identificar columnas de identificación
    /** @var string[] */
    private array $identificationPatterns = [
        'cedula', 'cédula', 'cc', 'documento', 'id', 'identificacion', 'identificación',
        'rfc', 'curp', 'nit', 'passport', 'pasaporte', 'dni', 'ci', 'rut', 'ruc',
        'numero', 'número', 'no', 'num', 'code', 'codigo', 'código'
    ];

    // Patrones para identificar columnas de nombres
    /** @var string[] */
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
     * @param string $filePath
     * @param string $fileType
     * @return array<string, mixed>
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
        /** @var string[] $allowedExtensions */
        $allowedExtensions = $this->config['files']['allowed_extensions'] ?? ['xlsx', 'xls', 'csv'];

        if (!isset($fileInfo['extension']) || !in_array(strtolower($fileInfo['extension']), $allowedExtensions)) {
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
     * @return array<string, mixed>
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
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $structure['detected_headers'][] = "Columna_{$col}";
            }
        }

        // Identificar columnas de identificación y nombre
        $this->identifyRelevantColumns($structure, $worksheet);

        return $structure;
    }

    /**
     * Obtiene los encabezados de una fila específica
     * @return array<int, string>
     */
    private function getRowHeaders(Worksheet $worksheet, int $row, string $highestColumn): array
    {
        /** @var array<int, string> $headers */
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
     * @param array<int, string> $headers
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
     * @param array<string, mixed> $structure
     */
    private function identifyRelevantColumns(array &$structure, Worksheet $worksheet): void
    {
        /** @var array<int, string> $headers */
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
     * @param array<string, mixed> $structure
     */
    private function identifyColumnsByData(array &$structure, Worksheet $worksheet): void
    {
        $dataStartRow = (int)$structure['data_start_row'];
        $totalRows = (int)$structure['total_rows'];
        $sampleRows = min(10, $totalRows - $dataStartRow + 1);

        /** @var array<int, array{numeric_count: int, text_count: int, avg_length: float, samples: string[]}> $columnAnalysis */
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
            if ($totalSamples === 0) {
                continue;
            }

            $avgLength = array_sum(array_map('strlen', $analysis['samples'])) / $totalSamples;
            $numericRatio = $analysis['numeric_count'] / $totalSamples;

            // Columna de identificación: principalmente numérica, longitud media
            if (
                $structure['identification_column'] === null &&
                $numericRatio > 0.7 &&
                $avgLength >= 5 && $avgLength <= 20
            ) {
                $structure['identification_column'] = $colIndex;
            }

            // Columna de nombre: principalmente texto, longitud mayor
            if (
                $structure['name_column'] === null &&
                $numericRatio < 0.3 &&
                $avgLength > 10
            ) {
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
     * @param array<string, mixed> $structure
     * @return array<int, array<string, mixed>>
     */
    private function extractData(Worksheet $worksheet, array $structure): array
    {
        /** @var array<int, array<string, mixed>> $data */
        $data = [];
        $idColIndex = $structure['identification_column'] ?? 1;
        $nameColIndex = $structure['name_column'] ?? 2;

        $idCol = $this->getExcelColumn((int)$idColIndex);
        $nameCol = $this->getExcelColumn((int)$nameColIndex);

        $maxRows = (int)($this->config['files']['max_excel_rows'] ?? 10000);
        $dataStartRow = (int)($structure['data_start_row'] ?? 1);
        $totalRows = (int)($structure['total_rows'] ?? $dataStartRow);
        $endRow = min($totalRows, $dataStartRow + $maxRows - 1);

        /** @var array<int, string> $detectedHeaders */
        $detectedHeaders = $structure['detected_headers'] ?? [];
        $highestDataColumn = $structure['total_columns'] ?? 'A';

        for ($row = $dataStartRow; $row <= $endRow; $row++) {
            $identification = trim((string)$worksheet->getCell($idCol . $row)->getCalculatedValue());
            $fullName = trim((string)$worksheet->getCell($nameCol . $row)->getCalculatedValue());

            // Extraer datos adicionales de otras columnas
            /** @var array<string, string> $additionalData */
            $additionalData = [];
            $col = 'A';
            $colIndex = 1;

            while ($col <= $highestDataColumn) {
                if ($colIndex !== $idColIndex && $colIndex !== $nameColIndex) {
                    $cellValue = trim((string)$worksheet->getCell($col . $row)->getCalculatedValue());
                    if (!empty($cellValue)) {
                        $headerName = $detectedHeaders[$colIndex] ?? "Columna_{$colIndex}";
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
     * @param array<int, array<string, mixed>> $data
     * @return array<int, array<string, mixed>>
     */
    private function validateExtractedData(array $data): array
    {
        /** @var array<int, array<string, mixed>> $validData */
        $validData = [];
        /** @var array<int, array<string, mixed>> $errors */
        $errors = [];

        foreach ($data as $index => $row) {
            $isValid = true;
            /** @var string[] $rowErrors */
            $rowErrors = [];

            /** @var string $identificationRaw */
            $identificationRaw = $row['identification'] ?? '';
            /** @var string $fullNameRaw */
            $fullNameRaw = $row['full_name'] ?? '';

            // Validar identificación
            if (empty(trim($identificationRaw)) && empty(trim($fullNameRaw))) {
                $isValid = false;
                $rowErrors[] = 'Tanto identificación como nombre están vacíos';
            }

            // Normalizar y limpiar datos
            $row['identification'] = $this->cleanIdentification($identificationRaw);
            $row['full_name'] = $this->cleanName($fullNameRaw);

            if ($isValid) {
                $validData[] = $row;
            } else {
                $errors[] = [
                    'row_number' => $row['row_number'] ?? ($index + 1) ,
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
    private function cleanIdentification(?string $id): string
    {
        if ($id === null) {
            return '';
        }
        // Remover espacios, guiones y caracteres especiales
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', trim($id));
        return strtoupper($cleaned ?? '');
    }

    /**
     * Limpia y normaliza el nombre
     */
    private function cleanName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        // Normalizar espacios y caracteres especiales
        $cleaned = preg_replace('/\s+/', ' ', trim($name));
        $cleaned = preg_replace('/[^\w\s\.\-ñÑáéíóúÁÉÍÓÚüÜ]/u', '', $cleaned ?? ''); // Added unicode support
        return ucwords(strtolower($cleaned ?? ''));
    }

    /**
     * Normaliza texto para comparaciones
     */
    private function normalizeText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]/', '', $text);
        return $text ?? '';
    }

    /**
     * Guarda datos como base de datos local
     * @param array<int, array<string, mixed>> $data
     * @return array<string, mixed>
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
     * @param array<int, array<string, mixed>> $data
     * @return array<string, mixed>
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
     * @return array<string, mixed>
     */
    public function getFileStatistics(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestColumn = $worksheet->getHighestDataColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            return [
                'file_size_mb' => round((float)filesize($filePath) / (1024 * 1024), 2),
                'total_rows' => $worksheet->getHighestRow(),
                'total_columns' => $highestColumnIndex,
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
