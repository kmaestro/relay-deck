<?php

declare(strict_types=1);

namespace ApiClient\Storage;

use ApiClient\Exception\ApiClientException;
use PDO;

/**
 * Persists environments, active selection and secret variable metadata.
 */
final readonly class EnvironmentStorage
{
    public function __construct(private PDO $database)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $environments = $this->database->query(
            'SELECT id, name, is_active, created_at, updated_at '
            . 'FROM environments ORDER BY name COLLATE NOCASE',
        )->fetchAll();
        $variables = $this->database->query(
            'SELECT id, environment_id, name, value, is_secret, enabled, sort_order '
            . 'FROM environment_variables ORDER BY sort_order, id',
        )->fetchAll();

        foreach ($environments as &$environment) {
            $environment['id'] = (int) $environment['id'];
            $environment['is_active'] = (bool) $environment['is_active'];
            $environment['variables'] = [];
        }
        unset($environment);

        $byId = [];

        foreach ($environments as $index => $environment) {
            $byId[$environment['id']] = $index;
        }

        foreach ($variables as $variable) {
            $environmentId = (int) $variable['environment_id'];

            if (!isset($byId[$environmentId])) {
                continue;
            }

            $variable['id'] = (int) $variable['id'];
            $variable['environment_id'] = $environmentId;
            $variable['is_secret'] = (bool) $variable['is_secret'];
            $variable['enabled'] = (bool) $variable['enabled'];
            $variable['sort_order'] = (int) $variable['sort_order'];
            $environments[$byId[$environmentId]]['variables'][] = $variable;
        }

        return array_values($environments);
    }

    public function activeId(): ?int
    {
        $value = $this->database->query(
            'SELECT id FROM environments WHERE is_active = 1 ORDER BY id LIMIT 1',
        )->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * @return array<string, string>
     */
    public function activeVariables(): array
    {
        $statement = $this->database->query(
            'SELECT v.name, v.value FROM environment_variables v '
            . 'INNER JOIN environments e ON e.id = v.environment_id '
            . 'WHERE e.is_active = 1 AND v.enabled = 1 ORDER BY v.sort_order, v.id',
        );
        $variables = [];

        foreach ($statement->fetchAll() as $row) {
            $variables[(string) $row['name']] = (string) $row['value'];
        }

        return $variables;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new ApiClientException('Environment name must contain 1 to 120 characters.', 'invalid_name');
        }

        $variables = $data['variables'] ?? [];

        if (!is_array($variables)) {
            throw new ApiClientException('Environment variables must be an array.', 'invalid_environment');
        }

        $id = isset($data['id']) && $data['id'] !== null && $data['id'] !== ''
            ? $this->positiveId($data['id'])
            : null;
        $this->database->beginTransaction();

        try {
            if ($id === null) {
                $isFirst = (int) $this->database->query('SELECT COUNT(*) FROM environments')->fetchColumn() === 0;
                $statement = $this->database->prepare(
                    'INSERT INTO environments (name, is_active) VALUES (:name, :is_active)',
                );
                $statement->execute(['name' => $name, 'is_active' => $isFirst ? 1 : 0]);
                $id = (int) $this->database->lastInsertId();
            } else {
                $statement = $this->database->prepare(
                    "UPDATE environments SET name = :name, updated_at = datetime('now') WHERE id = :id",
                );
                $statement->execute(['id' => $id, 'name' => $name]);

                if ($statement->rowCount() === 0) {
                    $exists = $this->database->prepare('SELECT 1 FROM environments WHERE id = :id');
                    $exists->execute(['id' => $id]);

                    if ($exists->fetchColumn() === false) {
                        throw new ApiClientException('Environment not found.', 'not_found');
                    }
                }
            }

            $statement = $this->database->prepare(
                'DELETE FROM environment_variables WHERE environment_id = :environment_id',
            );
            $statement->execute(['environment_id' => $id]);
            $insert = $this->database->prepare(
                'INSERT INTO environment_variables '
                . '(environment_id, name, value, is_secret, enabled, sort_order) '
                . 'VALUES (:environment_id, :name, :value, :is_secret, :enabled, :sort_order)',
            );
            $usedNames = [];

            foreach (array_values($variables) as $sortOrder => $variable) {
                if (!is_array($variable)) {
                    continue;
                }

                $variableName = trim((string) ($variable['name'] ?? $variable['key'] ?? ''));

                if ($variableName === '') {
                    continue;
                }

                if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $variableName) !== 1) {
                    throw new ApiClientException(
                        sprintf('Invalid variable name "%s".', $variableName),
                        'invalid_variable',
                    );
                }

                if (isset($usedNames[$variableName])) {
                    throw new ApiClientException(
                        sprintf('Variable "%s" is defined more than once.', $variableName),
                        'duplicate_variable',
                    );
                }

                $usedNames[$variableName] = true;
                $insert->execute([
                    'environment_id' => $id,
                    'name' => $variableName,
                    'value' => (string) ($variable['value'] ?? ''),
                    'is_secret' => !empty($variable['is_secret']) ? 1 : 0,
                    'enabled' => ($variable['enabled'] ?? true) ? 1 : 0,
                    'sort_order' => $sortOrder,
                ]);
            }

            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }

        return $this->find($id);
    }

    public function activate(int $id): void
    {
        $statement = $this->database->prepare('SELECT 1 FROM environments WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ($statement->fetchColumn() === false) {
            throw new ApiClientException('Environment not found.', 'not_found');
        }

        $this->database->beginTransaction();

        try {
            $this->database->exec('UPDATE environments SET is_active = 0');
            $statement = $this->database->prepare('UPDATE environments SET is_active = 1 WHERE id = :id');
            $statement->execute(['id' => $id]);
            $this->database->commit();
        } catch (\Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('SELECT is_active FROM environments WHERE id = :id');
        $statement->execute(['id' => $id]);
        $wasActive = $statement->fetchColumn();

        if ($wasActive === false) {
            throw new ApiClientException('Environment not found.', 'not_found');
        }

        $statement = $this->database->prepare('DELETE FROM environments WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ((bool) $wasActive) {
            $this->database->exec(
                'UPDATE environments SET is_active = 1 WHERE id = (SELECT id FROM environments ORDER BY id LIMIT 1)',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        foreach ($this->all() as $environment) {
            if ($environment['id'] === $id) {
                return $environment;
            }
        }

        throw new ApiClientException('Environment not found.', 'not_found');
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new ApiClientException('A valid environment identifier is required.', 'invalid_id');
        }

        return $id;
    }
}
