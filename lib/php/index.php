<?php
declare(strict_types=1);

/**
 * JSONDB Lite database engine.
 * Pure PHP core logic only: no HTTP headers, request parsing, routing, or third-party packages.
 */
final class JSONDBLite
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'db.json';
    }

    private function ensure(): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create database directory.');
        }
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode(['collections' => new stdClass()], JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    private function read(): array
    {
        $this->ensure();
        $raw = file_get_contents($this->file);
        $db = json_decode($raw === false || $raw === '' ? '{"collections":{}}' : $raw, true);
        if (!is_array($db) || !isset($db['collections']) || !is_array($db['collections'])) {
            throw new RuntimeException('Database JSON is invalid. Back up and repair data/db.json.');
        }
        return $db;
    }

    private function write(array $db): void
    {
        $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Could not encode database JSON.');
        $temp = $this->file . '.' . getmypid() . '.tmp';
        $written = file_put_contents($temp, $json, LOCK_EX);
        if ($written === false || !rename($temp, $this->file)) {
            @unlink($temp);
            throw new RuntimeException('Could not save database file.');
        }
    }

    private function validateCollection(string $collection): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $collection) !== 1) {
            throw new InvalidArgumentException('Invalid collection. Use 1-80 letters, numbers, underscores, or hyphens.');
        }
    }

    private function collection(array &$db, string $name): array
    {
        if (!isset($db['collections'][$name]) || !is_array($db['collections'][$name])) {
            $db['collections'][$name] = [];
        }
        return $db['collections'][$name];
    }

    private function valueAtPath($document, string $path)
    {
        $value = $document;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return null;
            $value = $value[$part];
        }
        return $value;
    }

    private function operatorMatch($actual, $expected): bool
    {
        if (is_array($expected) && !array_is_list($expected)) {
            foreach ($expected as $operator => $value) {
                switch ($operator) {
                    case '$contains':
                        if (!is_string($actual) || strpos($actual, (string)$value) === false) return false;
                        break;
                    case '$gt': if (!($actual > $value)) return false; break;
                    case '$gte': if (!($actual >= $value)) return false; break;
                    case '$lt': if (!($actual < $value)) return false; break;
                    case '$lte': if (!($actual <= $value)) return false; break;
                    case '$ne': if ($actual === $value) return false; break;
                    case '$in': if (!is_array($value) || !in_array($actual, $value, true)) return false; break;
                    default: return false;
                }
            }
            return true;
        }
        return $actual === $expected;
    }

    private function matches(array $document, array $query): bool
    {
        foreach ($query as $key => $expected) {
            if (!$this->operatorMatch($this->valueAtPath($document, (string)$key), $expected)) return false;
        }
        return true;
    }

    public function insert(string $collection, $data): array
    {
        $this->validateCollection($collection);
        $rows = is_array($data) && array_is_list($data) ? $data : [$data];
        if (!$rows || count(array_filter($rows, fn($row) => !is_array($row) || array_is_list($row))) > 0) {
            throw new InvalidArgumentException('Insert data must be a JSON object or an array of JSON objects.');
        }
        $db = $this->read();
        $documents = $this->collection($db, $collection);
        $inserted = [];
        foreach ($rows as $row) {
            $row['_id'] = $row['_id'] ?? bin2hex(random_bytes(16));
            $row['_createdAt'] = $row['_createdAt'] ?? gmdate('c');
            $documents[] = $row;
            $inserted[] = $row;
        }
        $db['collections'][$collection] = $documents;
        $this->write($db);
        return ['insertedCount' => count($inserted), 'data' => $inserted];
    }

    public function search(string $collection, array $query = [], array $options = []): array
    {
        $this->validateCollection($collection);
        $db = $this->read();
        $documents = $this->collection($db, $collection);
        $filtered = array_values(array_filter($documents, fn($doc) => is_array($doc) && $this->matches($doc, $query)));
        $offset = max(0, (int)($options['offset'] ?? 0));
        $limit = min(1000, max(0, (int)($options['limit'] ?? 100)));
        return ['count' => count($filtered), 'data' => array_slice($filtered, $offset, $limit)];
    }

    public function update(string $collection, array $query, array $data): array
    {
        $this->validateCollection($collection);
        if (!$query) throw new InvalidArgumentException('Update requires a non-empty query object.');
        unset($data['_id'], $data['_createdAt']);
        $db = $this->read();
        $documents = $this->collection($db, $collection);
        $updatedCount = 0;
        foreach ($documents as &$doc) {
            if (is_array($doc) && $this->matches($doc, $query)) {
                $id = $doc['_id'] ?? bin2hex(random_bytes(16));
                $created = $doc['_createdAt'] ?? gmdate('c');
                $doc = array_merge($doc, $data);
                $doc['_id'] = $id;
                $doc['_createdAt'] = $created;
                $doc['_updatedAt'] = gmdate('c');
                $updatedCount++;
            }
        }
        unset($doc);
        $db['collections'][$collection] = $documents;
        $this->write($db);
        return ['updatedCount' => $updatedCount];
    }

    public function delete(string $collection, array $query): array
    {
        $this->validateCollection($collection);
        if (!$query) throw new InvalidArgumentException('Delete requires a non-empty query object.');
        $db = $this->read();
        $documents = $this->collection($db, $collection);
        $remaining = [];
        foreach ($documents as $doc) {
            if (!is_array($doc) || !$this->matches($doc, $query)) $remaining[] = $doc;
        }
        $deletedCount = count($documents) - count($remaining);
        $db['collections'][$collection] = $remaining;
        $this->write($db);
        return ['deletedCount' => $deletedCount];
    }

    public function health(): array
    {
        $this->ensure();
        return ['status' => 'ok'];
    }
}
