<?php

declare(strict_types=1);

namespace SesamePortal;

trait DataHelpers
{
    private static function countRowsByColumn(string $table, string $column, int $id): int
    {
        $stmt = DB::pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private static function rowById(string $table, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function userByLogin(string $login): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE login = ?');
        $stmt->execute([$login]);
        return $stmt->fetch() ?: null;
    }

    private static function rowsByIds(string $table, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = $table === 'cameras' ? self::apiCameraById($id) : self::rowById($table, $id);
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function missingIds(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = DB::pdo()->prepare('SELECT id FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $existing = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(), 'id')), true);
        return array_values(array_filter($ids, static fn(int $id): bool => !isset($existing[$id])));
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private static function replaceLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        $pdo = DB::pdo();
        $pdo->prepare("DELETE FROM {$table} WHERE {$ownerKey} = ?")->execute([$ownerId]);
        $stmt = $pdo->prepare(DB::insertIgnoreSql($table, [$ownerKey, $targetKey]));
        foreach ($values as $value) {
            $stmt->execute([$ownerId, (int)$value]);
        }
    }

    private static function addLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        $stmt = DB::pdo()->prepare(DB::insertIgnoreSql($table, [$ownerKey, $targetKey]));
        foreach ($values as $value) {
            $stmt->execute([$ownerId, (int)$value]);
        }
    }

    private static function removeLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        if ($values === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $stmt = DB::pdo()->prepare("DELETE FROM {$table} WHERE {$ownerKey} = ? AND {$targetKey} IN ({$placeholders})");
        $stmt->execute([$ownerId, ...array_map('intval', $values)]);
    }

    private static function linkedIds(string $table, string $ownerKey, int $ownerId, string $targetKey): array
    {
        $stmt = DB::pdo()->prepare("SELECT {$targetKey} FROM {$table} WHERE {$ownerKey} = ?");
        $stmt->execute([$ownerId]);
        return array_map('intval', array_column($stmt->fetchAll(), $targetKey));
    }

    private static function randomActiveServerId(): ?int
    {
        $servers = DB::pdo()->query('SELECT id FROM dvr_servers WHERE blocked = 0 ORDER BY ' . DB::randomOrderSql() . ' LIMIT 1')->fetchAll();
        return $servers ? (int)$servers[0]['id'] : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        $value = trim((string)$value);
        return $value === '' ? null : (float)$value;
    }

    private static function coordinateFromInput(mixed $value, float $min, float $max): ?float
    {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $coordinate = (float)$value;
        if (!is_finite($coordinate) || $coordinate < $min || $coordinate > $max) {
            return null;
        }

        return $coordinate;
    }
}
