<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;

trait AuditPages
{
    private static function audit(): void
    {
        Auth::requireAdmin();
        $list = self::filteredAudit();
        $actions = DB::pdo()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC')->fetchAll();
        $actors = DB::pdo()->query('SELECT DISTINCT u.id, u.login FROM audit_logs a JOIN users u ON u.id = a.actor_user_id ORDER BY u.login ASC')->fetchAll();

        self::layout(self::t('audit.title', 'Журнал действий'), function () use ($list, $actions, $actors) {
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('audit.title', 'Журнал действий') . '</h2></div>';
            echo '<form method="get" action="/admin/audit" class="audit-filters">';
            echo '<input name="q" value="' . Util::h($list['q']) . '" placeholder="' . self::t('audit.search', 'Поиск по действию, пользователю или деталям') . '">';
            echo '<select name="action"><option value="">' . self::t('audit.allActions', 'Все действия') . '</option>';
            foreach ($actions as $action) {
                echo '<option value="' . Util::h($action['action']) . '" ' . ($list['action'] === $action['action'] ? 'selected' : '') . '>' . Util::h($action['action']) . '</option>';
            }
            echo '</select><select name="actor"><option value="">' . self::t('audit.allUsers', 'Все пользователи') . '</option>';
            foreach ($actors as $actor) {
                echo '<option value="' . (int)$actor['id'] . '" ' . ((int)$list['actor'] === (int)$actor['id'] ? 'selected' : '') . '>' . Util::h($actor['login']) . '</option>';
            }
            echo '</select><button>' . self::t('action.show', 'Показать') . '</button></form>';
            echo '<div class="table-wrap"><table class="data-table table-audit"><thead><tr><th>' . self::t('audit.time', 'Время') . '</th><th>' . self::t('audit.user', 'Пользователь') . '</th><th>' . self::t('audit.action', 'Действие') . '</th><th>' . self::t('audit.details', 'Детали') . '</th></tr></thead><tbody>';
            foreach ($list['rows'] as $row) {
                echo '<tr><td>' . self::localTime($row['created_at'] ?? '') . '</td><td>' . Util::h($row['login'] ?? '-') . '</td><td><code class="audit-action">' . Util::h($row['action']) . '</code></td><td>';
                self::auditDetails((string)$row['details']);
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            self::pager('/admin/audit', $list, ['action' => $list['action'], 'actor' => $list['actor']]);
            echo '</section>';
        });
    }

    private static function filteredAudit(int $pageSize = 50): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $action = trim((string)($_GET['action'] ?? ''));
        $actor = (int)($_GET['actor'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], ['a.action', 'a.details', 'u.login'])) . ')';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        if ($action !== '') {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        if ($actor > 0) {
            $where[] = 'a.actor_user_id = ?';
            $params[] = $actor;
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id' . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT a.*, u.login FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id' . $sqlWhere . ' ORDER BY a.id DESC LIMIT ? OFFSET ?');
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'q' => $q,
            'action' => $action,
            'actor' => $actor,
        ];
    }

    private static function auditDetails(string $details): void
    {
        $details = trim($details);
        if ($details === '') {
            echo '-';
            return;
        }

        $json = json_decode($details, true);
        if (is_array($json)) {
            echo '<dl class="audit-details">';
            foreach ($json as $key => $value) {
                echo '<dt>' . Util::h((string)$key) . '</dt><dd>' . Util::h(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</dd>';
            }
            echo '</dl>';
            return;
        }

        preg_match_all('/(?:^|\\s)([A-Za-z0-9_.-]+)=([^\\s]+)/', $details, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$matches) {
            echo '<div class="audit-details audit-details-text">' . Util::h($details) . '</div>';
            return;
        }

        echo '<div class="audit-details">';
        $context = trim(substr($details, 0, (int)$matches[0][0][1]));
        if ($context !== '') {
            echo '<span class="audit-context">' . Util::h($context) . '</span>';
        }
        foreach ($matches as $match) {
            echo '<span><strong>' . Util::h($match[1][0]) . '</strong> ' . Util::h($match[2][0]) . '</span>';
        }
        if (strlen($details) > 120 || count($matches) > 1) {
            echo '<details class="audit-raw"><summary>' . self::t('audit.raw', 'Полный текст') . '</summary><pre>' . Util::h($details) . '</pre></details>';
        }
        echo '</div>';
    }
}
