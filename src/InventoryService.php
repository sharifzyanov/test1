<?php

declare(strict_types=1);

namespace Warehouse;

use RuntimeException;

/**
 * InventoryService provides a minimalistic persistence-backed service
 * for storing information about warehouse items and recording stock
 * movements.
 */
class InventoryService
{
    private string $storageFile;

    /**
     * In-memory cache of inventory data.
     *
     * @var array{items: list<array<string,mixed>>, movements: list<array<string,mixed>>}
     */
    private array $data;

    public function __construct(string $storageFile)
    {
        $this->storageFile = $storageFile;
        $this->ensureStorageExists();
        $this->data = $this->load();
    }

    /**
     * Return all registered items ordered by SKU.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listItems(): array
    {
        $items = $this->data['items'];
        usort($items, static fn ($a, $b) => strcmp((string) $a['sku'], (string) $b['sku']));

        return $items;
    }

    /**
     * Retrieve a single item by its identifier.
     *
     * @throws RuntimeException when the item does not exist
     *
     * @return array<string, mixed>
     */
    public function getItem(string $id): array
    {
        foreach ($this->data['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        throw new RuntimeException("Item {$id} not found");
    }

    /**
     * Create a new item in the inventory.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    public function createItem(array $payload): array
    {
        $payload = $this->validateItemPayload($payload, true);

        $item = [
            'id' => $this->generateId(),
            'name' => $payload['name'],
            'sku' => $payload['sku'],
            'location' => $payload['location'],
            'quantity' => (int) $payload['quantity'],
            'reorder_level' => $payload['reorder_level'] ?? null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ];

        $this->data['items'][] = $item;
        $this->persist();

        $this->recordMovement([
            'type' => 'initial',
            'item_id' => $item['id'],
            'quantity_change' => $item['quantity'],
            'note' => 'Initial stock level',
        ]);

        return $item;
    }

    /**
     * Update the details of an existing item.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    public function updateItem(string $id, array $payload): array
    {
        $payload = $this->validateItemPayload($payload, false);
        $updated = null;

        foreach ($this->data['items'] as &$item) {
            if ($item['id'] === $id) {
                $item['name'] = $payload['name'] ?? $item['name'];
                $item['sku'] = $payload['sku'] ?? $item['sku'];
                $item['location'] = $payload['location'] ?? $item['location'];
                if (array_key_exists('reorder_level', $payload)) {
                    $item['reorder_level'] = $payload['reorder_level'];
                }
                $item['updated_at'] = $this->now();
                $updated = $item;
                break;
            }
        }
        unset($item);

        if ($updated === null) {
            throw new RuntimeException("Item {$id} not found");
        }

        $this->persist();

        return $updated;
    }

    /**
     * Adjust the quantity of an item by recording an inbound or outbound
     * stock movement.
     *
     * @param array{reason?: string} $metadata
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    public function adjustStock(string $id, int $difference, array $metadata = []): array
    {
        if ($difference === 0) {
            throw new ValidationException(['quantity' => 'Quantity adjustment must be non-zero.']);
        }

        $reason = $metadata['reason'] ?? ($difference > 0 ? 'Restock' : 'Dispatch');
        $item = null;

        foreach ($this->data['items'] as &$candidate) {
            if ($candidate['id'] === $id) {
                $newQuantity = $candidate['quantity'] + $difference;
                if ($newQuantity < 0) {
                    throw new ValidationException(['quantity' => 'Cannot reduce stock below zero.']);
                }
                $candidate['quantity'] = $newQuantity;
                $candidate['updated_at'] = $this->now();
                $item = $candidate;
                break;
            }
        }
        unset($candidate);

        if ($item === null) {
            throw new RuntimeException("Item {$id} not found");
        }

        $movement = $this->recordMovement([
            'type' => $difference > 0 ? 'inbound' : 'outbound',
            'item_id' => $item['id'],
            'quantity_change' => $difference,
            'note' => $reason,
        ]);

        $this->persist();

        return [
            'item' => $item,
            'movement' => $movement,
        ];
    }

    /**
     * Return stock movement history.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMovements(): array
    {
        $movements = $this->data['movements'];
        usort($movements, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $movements;
    }

    /**
     * @param array{type: string, item_id: string, quantity_change: int, note?: string} $payload
     *
     * @return array<string, mixed>
     */
    private function recordMovement(array $payload): array
    {
        $movement = [
            'id' => $this->generateId(),
            'type' => $payload['type'],
            'item_id' => $payload['item_id'],
            'quantity_change' => (int) $payload['quantity_change'],
            'note' => $payload['note'] ?? null,
            'created_at' => $this->now(),
        ];

        $this->data['movements'][] = $movement;
        $this->persist();

        return $movement;
    }

    private function ensureStorageExists(): void
    {
        if (!file_exists($this->storageFile)) {
            $directory = dirname($this->storageFile);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create storage directory: {$directory}");
            }

            $initial = json_encode(['items' => [], 'movements' => []], JSON_PRETTY_PRINT);
            if ($initial === false) {
                throw new RuntimeException('Failed to prepare initial storage content.');
            }

            if (file_put_contents($this->storageFile, $initial . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Unable to create storage file.');
            }
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, movements: list<array<string, mixed>>}
     */
    private function load(): array
    {
        $content = file_get_contents($this->storageFile);
        if ($content === false) {
            throw new RuntimeException('Unable to read storage file.');
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException('Storage file is corrupted.');
        }

        $data['items'] = array_values($data['items'] ?? []);
        $data['movements'] = array_values($data['movements'] ?? []);

        return $data;
    }

    private function persist(): void
    {
        $encoded = json_encode($this->data, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode inventory data.');
        }

        if (file_put_contents($this->storageFile, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist inventory data.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validateItemPayload(array $payload, bool $requireQuantity): array
    {
        $errors = [];

        if ($requireQuantity) {
            if (!isset($payload['quantity']) || !is_numeric($payload['quantity'])) {
                $errors['quantity'] = 'Quantity is required and must be numeric.';
            } elseif ((int) $payload['quantity'] < 0) {
                $errors['quantity'] = 'Quantity must be zero or greater.';
            }
        } elseif (isset($payload['quantity'])) {
            $errors['quantity'] = 'Quantity cannot be changed directly; use stock adjustments.';
        }

        if ($requireQuantity || array_key_exists('name', $payload)) {
            $name = $payload['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                $errors['name'] = 'Name is required.';
            }
        }

        if ($requireQuantity || array_key_exists('sku', $payload)) {
            $sku = $payload['sku'] ?? null;
            if (!is_string($sku) || trim($sku) === '') {
                $errors['sku'] = 'SKU is required.';
            }
        }

        if ($requireQuantity || array_key_exists('location', $payload)) {
            $location = $payload['location'] ?? null;
            if (!is_string($location) || trim($location) === '') {
                $errors['location'] = 'Location is required.';
            }
        }

        if (array_key_exists('reorder_level', $payload)) {
            $reorder = $payload['reorder_level'];
            if ($reorder !== null && (!is_numeric($reorder) || (int) $reorder < 0)) {
                $errors['reorder_level'] = 'Reorder level must be zero or greater when provided.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $payload;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
