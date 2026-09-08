<?php

declare(strict_types=1);

namespace SesamePortal;

trait AuditApi
{
    private static function apiAudit(array $parts): void
    {
        self::apiRequireAdmin();
        if (count($parts) !== 1 || self::apiMethod() !== 'GET') {
            self::apiError(404, 'not_found', 'Unknown audit endpoint');
            return;
        }
        $list = self::filteredAudit(self::apiPageSize(50, 500));
        self::apiJson(['events' => array_map([self::class, 'apiAuditRow'], $list['rows']), 'pagination' => self::apiPagination($list)]);
    }

    private static function apiAuditRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'actorUserId' => $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null,
            'actorLogin' => $row['login'] ?? null,
            'action' => (string)$row['action'],
            'details' => (string)$row['details'],
            'createdAt' => (string)$row['created_at'],
        ];
    }
}
