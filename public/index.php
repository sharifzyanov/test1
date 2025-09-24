<?php

declare(strict_types=1);

use Warehouse\InventoryService;
use Warehouse\ValidationException;

require_once __DIR__ . '/../src/InventoryService.php';
require_once __DIR__ . '/../src/ValidationException.php';

$storageFile = __DIR__ . '/../data/inventory.json';
$service = new InventoryService($storageFile);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

try {
    switch ($segments[0] ?? '') {
        case 'items':
            handleItems($service, $method, $segments);
            break;
        case 'movements':
            handleMovements($service, $method);
            break;
        case '':
            sendJson(200, [
                'service' => 'Warehouse inventory API',
                'endpoints' => [
                    'GET /items',
                    'GET /items/{id}',
                    'POST /items',
                    'PUT /items/{id}',
                    'POST /items/{id}/adjust',
                    'GET /movements',
                ],
            ]);
            break;
        default:
            sendJson(404, ['error' => 'Route not found']);
    }
} catch (ValidationException $exception) {
    sendJson(422, [
        'error' => $exception->getMessage(),
        'details' => $exception->getErrors(),
    ]);
} catch (Throwable $exception) {
    sendJson(500, [
        'error' => 'Unexpected error',
        'message' => $exception->getMessage(),
    ]);
}

function handleItems(InventoryService $service, string $method, array $segments): void
{
    if ($method === 'GET' && count($segments) === 1) {
        sendJson(200, $service->listItems());
        return;
    }

    if ($method === 'POST' && count($segments) === 1) {
        $payload = decodeJsonBody();
        $item = $service->createItem($payload);
        sendJson(201, $item);
        return;
    }

    if (count($segments) >= 2) {
        $itemId = $segments[1];

        if ($method === 'GET' && count($segments) === 2) {
            $item = $service->getItem($itemId);
            sendJson(200, $item);
            return;
        }

        if ($method === 'PUT' && count($segments) === 2) {
            $payload = decodeJsonBody();
            $item = $service->updateItem($itemId, $payload);
            sendJson(200, $item);
            return;
        }

        if ($method === 'POST' && count($segments) === 3 && $segments[2] === 'adjust') {
            $payload = decodeJsonBody();
            $quantity = isset($payload['quantity']) ? (int) $payload['quantity'] : 0;
            $result = $service->adjustStock($itemId, $quantity, [
                'reason' => $payload['reason'] ?? null,
            ]);
            sendJson(200, $result);
            return;
        }
    }

    sendJson(405, ['error' => 'Unsupported method for /items']);
}

function handleMovements(InventoryService $service, string $method): void
{
    if ($method !== 'GET') {
        sendJson(405, ['error' => 'Only GET is supported for /movements']);
        return;
    }

    sendJson(200, $service->listMovements());
}

/**
 * @return array<string, mixed>
 */
function decodeJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        throw new RuntimeException('Unable to read request body');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new ValidationException(['body' => 'Request body must be valid JSON object.']);
    }

    return $payload;
}

/**
 * @param array<string, mixed>|list<mixed> $data
 */
function sendJson(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
