<?php

declare(strict_types=1);

namespace ApiClient\Storage;

use ApiClient\DTO\HttpRequest;
use ApiClient\DTO\HttpResponse;
use ApiClient\Exception\ApiClientException;
use PDO;
use Throwable;

/**
 * Persists reusable request definitions and a bounded execution history.
 */
final readonly class RequestStorage
{
    private const int HISTORY_LIMIT = 200;

    public function __construct(private PDO $database)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function savedRequests(): array
    {
        $rows = $this->database->query(
            'SELECT id, collection_id, folder_id, name, method, url, request_json, created_at, updated_at '
            . 'FROM saved_requests ORDER BY name COLLATE NOCASE',
        )->fetchAll();

        return array_map($this->mapSavedRequest(...), $rows);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $name = $this->requestName($data['name'] ?? null);

        $collectionId = $this->positiveId($data['collectionId'] ?? $data['collection_id'] ?? null);
        $folderId = isset($data['folderId']) && $data['folderId'] !== null && $data['folderId'] !== ''
            ? $this->positiveId($data['folderId'])
            : null;
        $requestData = $data['request'] ?? $data;

        if (!is_array($requestData)) {
            throw new ApiClientException('Request data is missing.', 'invalid_request');
        }

        $request = HttpRequest::fromArray($requestData);
        $this->assertLocation($collectionId, $folderId);
        $encoded = json_encode(
            $request->toArray(includeFileContents: false),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $id = isset($data['id']) && $data['id'] !== null && $data['id'] !== ''
            ? $this->positiveId($data['id'])
            : null;

        if ($id === null) {
            $statement = $this->database->prepare(
                'INSERT INTO saved_requests '
                . '(collection_id, folder_id, name, method, url, request_json) '
                . 'VALUES (:collection_id, :folder_id, :name, :method, :url, :request_json)',
            );
            $statement->execute([
                'collection_id' => $collectionId,
                'folder_id' => $folderId,
                'name' => $name,
                'method' => $request->method,
                'url' => $request->url,
                'request_json' => $encoded,
            ]);
            $id = (int) $this->database->lastInsertId();
        } else {
            $statement = $this->database->prepare(
                "UPDATE saved_requests SET collection_id = :collection_id, folder_id = :folder_id, "
                . 'name = :name, method = :method, url = :url, request_json = :request_json, '
                . "updated_at = datetime('now') WHERE id = :id",
            );
            $statement->execute([
                'id' => $id,
                'collection_id' => $collectionId,
                'folder_id' => $folderId,
                'name' => $name,
                'method' => $request->method,
                'url' => $request->url,
                'request_json' => $encoded,
            ]);
        }

        return $this->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rename(mixed $id, mixed $name): array
    {
        $id = $this->positiveId($id);
        $name = $this->requestName($name);
        $statement = $this->database->prepare(
            "UPDATE saved_requests SET name = :name, updated_at = datetime('now') WHERE id = :id",
        );
        $statement->execute(['id' => $id, 'name' => $name]);

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM saved_requests WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ($statement->rowCount() === 0) {
            throw new ApiClientException('Saved request not found.', 'not_found');
        }
    }

    public function addHistory(
        HttpRequest $request,
        ?HttpResponse $response = null,
        ?Throwable $error = null,
    ): void {
        $errorType = null;

        if ($error instanceof ApiClientException) {
            $errorType = $error->errorType;
        } elseif ($error !== null) {
            $errorType = 'application_error';
        }

        $statement = $this->database->prepare(
            'INSERT INTO history '
            . '(method, url, status_code, duration_ms, request_json, error_type, error_message) '
            . 'VALUES (:method, :url, :status_code, :duration_ms, :request_json, :error_type, :error_message)',
        );
        $statement->execute([
            'method' => $request->method,
            'url' => $request->url,
            'status_code' => $response?->statusCode,
            'duration_ms' => $response?->durationMs,
            'request_json' => json_encode(
                $request->toArray(includeFileContents: false),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'error_type' => $errorType,
            'error_message' => $error?->getMessage(),
        ]);

        $this->database->exec(
            'DELETE FROM history WHERE id NOT IN '
            . '(SELECT id FROM history ORDER BY id DESC LIMIT ' . self::HISTORY_LIMIT . ')',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 100): array
    {
        $limit = max(1, min(self::HISTORY_LIMIT, $limit));
        $statement = $this->database->prepare(
            'SELECT id, method, url, status_code, duration_ms, request_json, error_type, error_message, created_at '
            . 'FROM history ORDER BY id DESC LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['status_code'] = $row['status_code'] === null ? null : (int) $row['status_code'];
            $row['duration_ms'] = $row['duration_ms'] === null ? null : (float) $row['duration_ms'];
            $row['request'] = json_decode((string) $row['request_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row['request_json']);
        }
        unset($row);

        return $rows;
    }

    public function clearHistory(): void
    {
        $this->database->exec('DELETE FROM history');
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        $statement = $this->database->prepare(
            'SELECT id, collection_id, folder_id, name, method, url, request_json, created_at, updated_at '
            . 'FROM saved_requests WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if ($row === false) {
            throw new ApiClientException('Saved request not found.', 'not_found');
        }

        return $this->mapSavedRequest($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapSavedRequest(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['collection_id'] = (int) $row['collection_id'];
        $row['folder_id'] = $row['folder_id'] === null ? null : (int) $row['folder_id'];
        $row['request'] = json_decode((string) $row['request_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['request_json']);

        return $row;
    }

    private function assertLocation(int $collectionId, ?int $folderId): void
    {
        $statement = $this->database->prepare('SELECT 1 FROM collections WHERE id = :id');
        $statement->execute(['id' => $collectionId]);

        if ($statement->fetchColumn() === false) {
            throw new ApiClientException('Collection not found.', 'not_found');
        }

        if ($folderId === null) {
            return;
        }

        $statement = $this->database->prepare(
            'SELECT 1 FROM folders WHERE id = :id AND collection_id = :collection_id',
        );
        $statement->execute(['id' => $folderId, 'collection_id' => $collectionId]);

        if ($statement->fetchColumn() === false) {
            throw new ApiClientException('Folder does not belong to this collection.', 'invalid_folder');
        }
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new ApiClientException('A valid identifier is required.', 'invalid_id');
        }

        return $id;
    }

    private function requestName(mixed $value): string
    {
        $name = is_scalar($value) || $value === null
            ? trim((string) $value)
            : '';

        if ($name === '' || mb_strlen($name) > 160) {
            throw new ApiClientException('Request name must contain 1 to 160 characters.', 'invalid_name');
        }

        return $name;
    }
}
