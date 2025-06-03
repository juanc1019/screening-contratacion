<?php

namespace ScreeningApp\Utils;

if (!function_exists('ScreeningApp\Utils\sendJsonResponse')) {
    /**
     * Sends a consistent JSON response.
     *
     * @param array<string, mixed> $data The data to send.
     * @param int $statusCode The HTTP status code.
     */
    function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('ScreeningApp\Utils\sendSuccess')) {
    /**
     * Sends a consistent JSON success response.
     *
     * @param array<string, mixed> $data Additional data to include in the response.
     * @param string|null $message Optional success message.
     * @param int $statusCode HTTP status code, defaults to 200.
     */
    function sendSuccess(array $data = [], ?string $message = null, int $statusCode = 200): void
    {
        $responseData = ['success' => true];
        if ($message !== null) {
            $responseData['message'] = $message;
        }
        sendJsonResponse(array_merge($responseData, $data), $statusCode);
    }
}

if (!function_exists('ScreeningApp\Utils\sendError')) {
    /**
     * Sends a consistent JSON error response.
     *
     * @param string $errorMessage The error message.
     * @param int $statusCode HTTP status code, defaults to 400.
     * @param array<string, mixed> $extraData Additional data to include in the error response.
     */
    function sendError(string $errorMessage, int $statusCode = 400, array $extraData = []): void
    {
        $responseData = [
            'success' => false,
            'error' => $errorMessage,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        sendJsonResponse(array_merge($responseData, $extraData), $statusCode);
    }
}

// Example of another potential helper:
// if (!function_exists('ScreeningApp\Utils\generateUuid')) {
//     function generateUuid(): string
//     {
//         return \Ramsey\Uuid\Uuid::uuid4()->toString();
//     }
// }

// Add other global helper functions below as needed.
