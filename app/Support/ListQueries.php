<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;

trait ListQueries
{
    private static function filteredRows(string $table, array $searchColumns, string $order, int $pageSize = 25): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = '';
        $params = [];

        if ($q !== '') {
            $likes = [];
            foreach ($searchColumns as $column) {
                $likes[] = DB::caseInsensitiveLike($column);
                $params[] = '%' . $q . '%';
            }
            $where = ' WHERE ' . implode(' OR ', $likes);
        }

        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT * FROM ' . $table . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'q' => $q];
    }

    private static function sqlPlaceholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
