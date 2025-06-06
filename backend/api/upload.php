<?php

/**
 * API para subida y procesamiento de archivos Excel
 * Maneja tanto bases de datos locales como archivos para búsqueda
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../utils/helpers.php'; // Include helpers

use ScreeningApp\Database;
use ScreeningApp\ExcelProcessor;
use ScreeningApp\QueueManager;
use function ScreeningApp\Utils\sendSuccess; // Import specific functions
use function ScreeningApp\Utils\sendError;   // Import specific functions

// Headers CORS y JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Método no permitido', 405);
}

try {
    // Cargar configuración
    $config = require __DIR__ . '/../config/app.php';

    // Validar que se subió un archivo
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        sendError('No se subió ningún archivo o hubo un error en la subida', 400);
    }

    $file = $_FILES['file'];
    $uploadType = $_POST['upload_type'] ?? 'search'; // 'search' o 'local_database'
    $description = $_POST['description'] ?? '';

    // Validar tipo de archivo
    $allowedExtensions = $config['files']['allowed_extensions'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($fileExtension, $allowedExtensions)) {
        sendError('Tipo de archivo no permitido. Permitidos: ' . implode(', ', $allowedExtensions), 415);
    }

    // Validar tamaño
    $maxSizeMB = $config['files']['max_size_mb'];
    $fileSizeMB = $file['size'] / (1024 * 1024);

    if ($fileSizeMB > $maxSizeMB) {
        sendError("El archivo excede el tamaño máximo permitido de {$maxSizeMB}MB", 413);
    }

    // Determinar directorio de destino
    $uploadDir = match ($uploadType) {
        'local_database' => $config['files']['local_db_dir'],
        'search' => $config['files']['search_files_dir'],
        default => sendError('Tipo de subida no válido', 400)
    };

    // Crear directorio si no existe
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendError('No se pudo crear el directorio de subida', 500);
        }
    }

    // Generar nombre único para el archivo
    $fileName = date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;

    // Mover archivo subido
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        sendError('Error moviendo el archivo subido', 500);
    }

    // Procesar archivo según el tipo
    $db = Database::getInstance();
    $processor = new ExcelProcessor();
    $queueManager = new QueueManager();

    // Obtener estadísticas rápidas del archivo
    $fileStats = $processor->getFileStatistics($filePath);

    if ($uploadType === 'local_database') {
        // Procesar inmediatamente si es base de datos local pequeña
        if ($fileStats['total_rows'] <= 1000) {
            $result = $processor->processFile($filePath, 'local_database');

            $response = [
                'success' => true,
                'message' => 'Archivo procesado exitosamente como base de datos local',
                'file_info' => [
                    'original_name' => $file['name'],
                    'stored_name' => $fileName,
                    'file_path' => $filePath,
                    'size_mb' => round($fileSizeMB, 2),
                    'type' => $uploadType
                ],
                'processing_result' => $result,
                'file_stats' => $fileStats,
                'processed_immediately' => true
            ];

            // Log de la operación
            $db->log('INFO', 'upload_api', 'Base de datos local procesada', [
                'file_name' => $file['name'],
                'records_saved' => $result['data']['saved'] ?? 0
            ]);
        } else {
            // Usar cola para archivos grandes
            $jobId = $queueManager->addJob('excel_processing', [
                'file_path' => $filePath,
                'file_type' => 'local_database',
                'original_name' => $file['name']
            ], 2); // Prioridad alta

            $response = [
                'success' => true,
                'message' => 'Archivo grande puesto en cola para procesamiento',
                'file_info' => [
                    'original_name' => $file['name'],
                    'stored_name' => $fileName,
                    'file_path' => $filePath,
                    'size_mb' => round($fileSizeMB, 2),
                    'type' => $uploadType
                ],
                'job_id' => $jobId,
                'file_stats' => $fileStats,
                'processed_immediately' => false,
                'estimated_processing_time' => $fileStats['estimated_processing_time']
            ];
        }
    } else {
        // Para archivos de búsqueda, solo validar y preparar
        $validationResult = $processor->processFile($filePath, 'search');

        if ($validationResult['success']) {
            $response = [
                'success' => true,
                'message' => 'Archivo de búsqueda validado y listo para procesamiento',
                'file_info' => [
                    'original_name' => $file['name'],
                    'stored_name' => $fileName,
                    'file_path' => $filePath,
                    'size_mb' => round($fileSizeMB, 2),
                    'type' => $uploadType
                ],
                'validation_result' => $validationResult,
                'file_stats' => $fileStats,
                'ready_for_batch' => true
            ];

            $db->log('INFO', 'upload_api', 'Archivo de búsqueda validado', [
                'file_name' => $file['name'],
                'valid_records' => $validationResult['statistics']['valid_rows'] ?? 0
            ]);
        } else {
            sendError('Error validando archivo: ' . ($validationResult['message'] ?? 'Error desconocido'));
        }
    }

    // Crear notificación
    $db->createNotification([
        'type' => 'success',
        'title' => 'Archivo Subido',
        'message' => "Archivo '{$file['name']}' subido exitosamente",
        'is_persistent' => false
    ]);

    // Enviar respuesta de éxito final
    // sendSuccess($response) no funcionaría directamente aquí porque $response ya tiene 'success' => true
    // y sendSuccess lo añadiría de nuevo.
    header('Content-Type: application/json');
    http_response_code(200); // O 201 si se creó un recurso y se devuelve su URI/info
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    error_log("Error en upload.php: " . $e->getMessage());

    // Limpiar archivo si se subió pero hubo error
    if (isset($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }

    // Log del error antes de enviar la respuesta
    try {
        if (!isset($db) || $db === null) { // Asegurar que $db está disponible
            $db = Database::getInstance();
        }
        $db->log('ERROR', 'upload_api', 'Error subiendo archivo', [
            'error' => $e->getMessage(),
            'file_name' => $_FILES['file']['name'] ?? 'unknown'
        ]);
    } catch (Exception $logError) {
        error_log("Error adicional en logging: " . $logError->getMessage());
    }
    
    sendError($e->getMessage());
}
