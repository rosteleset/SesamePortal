# Миграция: SQLite → PostgreSQL (выполнено)

Перенос хранилища SesamePortal с SQLite на PostgreSQL 18 (локальный кластер `18-main`,
БД `sesame_portal`, роль `sesame`) с сохранением всех данных и автоматической
синхронизацией identity-последовательностей при восстановлении из бэкапа.

## Итоговое состояние

- Хранилище: PostgreSQL 18, БД `sesame_portal` (прод) и `sesame_portal_test` (тесты),
  роль `sesame` / пароль `sesame-pass`, слушает `127.0.0.1:5432`.
- Драйвер: `php8.3-pgsql` (`pdo_pgsql`, `pgsql` загружены).
- `var/config.php`: `db_dsn => 'pgsql:host=127.0.0.1;port=5432;dbname=sesame_portal'`,
  `db_user => 'sesame'`, `db_password => 'sesame-pass'`.
- Данные перенесены из `var/portal.sqlite` через `backup` → `restore`; объёмы:
  2 users, 1 group, 1 server, 8 cameras, 4 camera_groups, 78 audit_logs, 0 favorites.
- Тесты: sqlite smoke ✅, pgsql smoke ✅ (`tests/http_smoke_pgsql.sh`).
- Бэкапы отката: `/tmp/sesame-backup.json`, `/tmp/portal.sqlite.bak`, `/tmp/config.php.bak`.

## Изменения в коде

- **Автосинк identity-последовательностей** — `DB::syncIdentity(string $table, string $column = 'id')`
  (app/Portal.php:225-243): `setval(pg_get_serial_sequence(), max(last_value, MAX(id)), true)`.
  Вызывается в `Cli::restore()` после commit для таблиц
  `IDENTITY_TABLES = [users, portal_groups, dvr_servers, cameras, audit_logs]`
  (app/Portal.php ~10323). Ручной Шаг 6 из прежнего плана больше не нужен.
  Существующий `Portal::syncPortalGroupIdentityAfterExplicitInsert()` переведён на общий
  хелпер (app/Portal.php:9943); старый `quoteQualifiedIdentifier()` удалён.
- **Pgsql-несовместимости SQL** (найдены pgsql-smoke, исправлены):
  - `Cli::rotateSecrets()`: `management_token_enc != ""` → `<> ''` — в PG `""` это
    пустой идентификатор, не строковый литерал (app/Portal.php:10248).
  - Dashboard `recentSync`: `COALESCE(c.last_sync_at, "")` → `''` (app/Portal.php:5734).
  - Список камер: `SELECT DISTINCT ... ORDER BY COALESCE(s.name,'')` — PG требует, чтобы
    выражения `ORDER BY` при `DISTINCT` присутствовали в выборке. Обёрнуто в субзапрос
    с вычислимыми псевдонимами `sort_server`/`sort_sync`; `cameraListSortColumns()` и
    `cameraListOrderSql()` переключены на имена выходных колонок
    (app/Portal.php:8693, 8806-8818, 8821-8828).

## Особенности конфигурации

- `Config::all()` (app/Portal.php:34-77) использует `array_replace(defaults, loaded)` —
  значения из `var/config.php` имеют приоритет над env. Правки БД вносятся в файл напрямую.
- `saveConfigValue()` перечитывает файл и перезаписывает через `var_export` — ключи БД
  сохраняются при последующих сохранениях настроек.

## Тесты

- `tests/http_smoke.sh`: config.php-heredoc берёт `db_dsn`/`db_user`/`db_password` из env
  (`SESAME_PORTAL_DB_DSN` и т.д.); sqlite-блок `sqlite_duplicate_group_migration` обёрнут
  в `if [[ -z "${SESAME_PORTAL_DB_DSN:-}" ]]`.
- `tests/http_smoke_pgsql.sh`: обёртка — `psql` DROP/CREATE SCHEMA на `sesame_portal_test`
  + запуск общего smoke с env DSN pgsql.
- Гейт `SESAME_PORTAL_DB_DSN` переключает путь: без него — SQLite (по умолчанию),
  с ним — указанный DSN. Smoke-тест создаёт своего `admin`/`admin123`.

## Порядок выполненной миграции (воспроизводимые шаги)

### 1. Бэкап (база отката)

```bash
php bin/portal backup /tmp/sesame-backup.json
cp var/portal.sqlite /tmp/portal.sqlite.bak
cp var/config.php /tmp/config.php.bak
```

### 2. Драйвер PG

```bash
sudo apt-get install -y php8.3-pgsql
php -m | grep -i pgsql        # pdo_pgsql, pgsql
```

### 3. Роль и БД

```bash
sudo -u postgres psql -c "CREATE ROLE sesame LOGIN PASSWORD 'sesame-pass'"
sudo -u postgres psql -c "CREATE DATABASE sesame_portal OWNER sesame"
sudo -u postgres psql -c "CREATE DATABASE sesame_portal_test OWNER sesame"
```

### 4. Конфиг (`var/config.php`)

```php
'db_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=sesame_portal',
'db_user' => 'sesame',
'db_password' => 'sesame-pass',
```

### 5. Заливка данных (схема + секвенсы создаются автоматически)

```bash
php bin/portal migrate
php bin/portal restore /tmp/sesame-backup.json
# restore() сам вызовет DB::syncIdentity() для identity-таблиц
```

### 6. Рестарт воркеров

```bash
# остановить старые процессы php -S 0.0.0.0:8080 (sqlite-конфиг ещё в кэше)
sudo pkill -f "php -S 0.0.0.0:8080"
# поднять заново (уже с pgsql-конфигом и pdo_pgsql)
setsid php -S 0.0.0.0:8080 -t public </dev/null >>/tmp/sesame_workers.out 2>&1 &
```

### 7. Верификация

```bash
php bin/portal migrate            # "migrated"
psql -d sesame_portal -c "\dt"
# страницы без 500: / , /viewer/map , /admin/cameras , /admin/dashboard , /login
# вставка/удаление камеры — проверка sequence и каскадов
```

### 8. Откат (при необходимости)

```bash
cp /tmp/config.php.bak var/config.php   # вернуть sqlite-конфиг
sudo pkill -f "php -S 0.0.0.0:8080"; setsid php -S 0.0.0.0:8080 -t public &
php bin/portal restore /tmp/sesame-backup.json   # заливка обратно в SQLite
```

## Риски и замечания

- **Автосинк секвенсов обязателен** — без него после restore первые INSERT падают с
  дублированием id. Теперь выполняется автоматически в `Cli::restore()`.
- `setForeignKeys(false)` — no-op для pgsql, но порядок вставки в `restore()` FK-безопасен.
- При рестарте dev-сервера: `php -S` не поддерживает `SO_REUSEPORT` — живёт один процесс
  на порту; лишние падают с "Address already in use" (это норма для dev-сервера, для
  production нужен php-fpm + nginx).
- Если dev-сервер с opcache — после смены `var/config.php` нужен рестарт (кэш статический
  на воркер).