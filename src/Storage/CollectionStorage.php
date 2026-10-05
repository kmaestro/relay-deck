<?php

declare(strict_types=1);

namespace ApiClient\Storage;

use ApiClient\Exception\ApiClientException;
use PDO;

/**
 * Persists collections and nested folders.
 */
final readonly class CollectionStorage
{
    public function __construct(private PDO $database)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $collections = $this->database
            ->query('SELECT id, name, description, created_at, updated_at FROM collections ORDER BY name COLLATE NOCASE')
            ->fetchAll();
        $folders = $this->database
            ->query('SELECT id, collection_id, parent_id, name, created_at, updated_at '
                . 'FROM folders ORDER BY name COLLATE NOCASE')
            ->fetchAll();

        foreach ($collections as &$collection) {
            $collection['id'] = (int) $collection['id'];
            $collection['folders'] = [];
        }
        unset($collection);

        $byId = [];

        foreach ($collections as $index => $collection) {
            $byId[$collection['id']] = $index;
        }

        foreach ($folders as $folder) {
            $collectionId = (int) $folder['collection_id'];

            if (!isset($byId[$collectionId])) {
                continue;
            }

            $folder['id'] = (int) $folder['id'];
            $folder['collection_id'] = $collectionId;
            $folder['parent_id'] = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
            $collections[$byId[$collectionId]]['folders'][] = $folder;
        }

        return array_values($collections);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createCollection(array $data): array
    {
        $name = $this->name($data['name'] ?? null, 'Collection');
        $description = trim((string) ($data['description'] ?? ''));
        $statement = $this->database->prepare(
            'INSERT INTO collections (name, description) VALUES (:name, :description)',
        );
        $statement->execute(['name' => $name, 'description' => $description]);

        return $this->findCollection((int) $this->database->lastInsertId());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateCollection(array $data): array
    {
        $id = $this->id($data['id'] ?? null);
        $name = $this->name($data['name'] ?? null, 'Collection');
        $description = trim((string) ($data['description'] ?? ''));
        $statement = $this->database->prepare(
            "UPDATE collections SET name = :name, description = :description, updated_at = datetime('now') "
            . 'WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'name' => $name, 'description' => $description]);

        if ($statement->rowCount() === 0) {
            $this->findCollection($id);
        }

        return $this->findCollection($id);
    }

    public function deleteCollection(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM collections WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ($statement->rowCount() === 0) {
            throw new ApiClientException('Collection not found.', 'not_found');
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createFolder(array $data): array
    {
        $collectionId = $this->id($data['collectionId'] ?? $data['collection_id'] ?? null);
        $name = $this->name($data['name'] ?? null, 'Folder');
        $parentId = isset($data['parentId']) && $data['parentId'] !== null
            ? $this->id($data['parentId'])
            : null;
        $this->findCollection($collectionId);

        if ($parentId !== null) {
            $parent = $this->findFolder($parentId);

            if ($parent['collection_id'] !== $collectionId) {
                throw new ApiClientException('Parent folder belongs to another collection.', 'invalid_folder');
            }
        }

        $statement = $this->database->prepare(
            'INSERT INTO folders (collection_id, parent_id, name) VALUES (:collection_id, :parent_id, :name)',
        );
        $statement->execute([
            'collection_id' => $collectionId,
            'parent_id' => $parentId,
            'name' => $name,
        ]);

        return $this->findFolder((int) $this->database->lastInsertId());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateFolder(array $data): array
    {
        $id = $this->id($data['id'] ?? null);
        $name = $this->name($data['name'] ?? null, 'Folder');
        $statement = $this->database->prepare(
            "UPDATE folders SET name = :name, updated_at = datetime('now') WHERE id = :id",
        );
        $statement->execute(['id' => $id, 'name' => $name]);

        return $this->findFolder($id);
    }

    public function deleteFolder(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM folders WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ($statement->rowCount() === 0) {
            throw new ApiClientException('Folder not found.', 'not_found');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findCollection(int $id): array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, description, created_at, updated_at FROM collections WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $collection = $statement->fetch();

        if ($collection === false) {
            throw new ApiClientException('Collection not found.', 'not_found');
        }

        $collection['id'] = (int) $collection['id'];

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    private function findFolder(int $id): array
    {
        $statement = $this->database->prepare(
            'SELECT id, collection_id, parent_id, name, created_at, updated_at FROM folders WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $folder = $statement->fetch();

        if ($folder === false) {
            throw new ApiClientException('Folder not found.', 'not_found');
        }

        $folder['id'] = (int) $folder['id'];
        $folder['collection_id'] = (int) $folder['collection_id'];
        $folder['parent_id'] = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];

        return $folder;
    }

    private function name(mixed $value, string $entity): string
    {
        $name = trim((string) $value);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new ApiClientException($entity . ' name must contain 1 to 120 characters.', 'invalid_name');
        }

        return $name;
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new ApiClientException('A valid identifier is required.', 'invalid_id');
        }

        return $id;
    }
}
