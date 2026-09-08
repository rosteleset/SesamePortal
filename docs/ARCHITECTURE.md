# Portal PHP Modules

`app/Portal.php` is the shared bootstrap for `public/index.php`, `bin/portal`,
and tests. Keep this entry point compatible: requiring it loads declarations,
but does not start a session, open the database, or run migrations. It uses
explicit `require_once` calls, so production does not require Composer autoloading.

## Module Map

| Location | Responsibility |
| --- | --- |
| `app/Config.php`, `app/DB.php`, `app/PortalSettings.php` | Runtime configuration, database schema/migrations, persisted Portal settings |
| `app/Auth.php`, `app/Csrf.php`, `app/Crypto.php`, `app/TokenService.php`, `app/Audit.php` | Sessions, authorization guards, CSRF, encryption, tokens, audit records |
| `app/Repo.php`, `app/DvrClient.php` | Camera/group access queries and DVR management requests |
| `app/MapTilesService.php`, `app/PortalUpdateService.php` | Map-provider integration and Portal update status/execution |
| `app/I18n.php`, `app/PortalI18nCatalog.php`, `app/Util.php` | Translation catalog and shared utilities |
| `app/Cli.php` | Migration, admin creation, token/secret rotation, backup and restore commands |
| `app/App.php` | HTTP startup sequence and route dispatch |
| `app/Api/` | Versioned API routing/validation and resource handlers for users, groups, cameras, DVR servers, agents, dashboard and audit |
| `app/Pages/` | Viewer, map, login, personal/admin settings and administration pages; camera import is separate from camera editing |
| `app/AuthBackend.php` | Playback authorization protocol, request parsing, archive restrictions and capability responses |
| `app/Ui/` | Page shell, navigation/icons, tables/pagination, forms and group-tree rendering |
| `app/Support/` | Shared form normalization, data/link operations, list queries, playback URLs and camera availability helpers |
| `app/VideoWalls.php`, `app/VideoWallPages.php`, `app/VideoWallTranslations.php` | Existing video wall persistence/access, HTTP/UI handlers and translations |

## Composition Rules

- Classes retain the `SesamePortal` namespace and their existing public contracts.
- API, page, Auth Backend, UI and support files contain traits composed into `App`.
  They are not independent controllers: they share private static helpers through
  `self::`. `App::run()` remains the only public application entry point.
- Keep handlers and their domain-specific helpers together. Only put helpers in
  `Support` or `Ui` when multiple areas use them. Do not add page implementations
  to the router or create a second database/authentication layer in a module.
- Existing SQL, group inheritance, API payloads, routes, authorization checks,
  sessions, localization keys and playback behavior are unchanged by the split.
- Use `Config::root()` for project-relative files in nested modules. Do not infer
  the project root from a page or trait's `__DIR__`. Add a moved/new module to the
  explicit bootstrap before the class that composes it.
- Continue loading `app/Portal.php` from scripts and tests rather than requiring
  individual implementation files or assuming a particular working directory.

This is a structural separation, not a framework migration or a performance
optimization. Independent services/controllers can be extracted later where
their dependencies justify it, without combining that work with new features.

## Verification and Releases

```bash
find app -name '*.php' -exec php -l {} \;
php tests/module_bootstrap.php
php tests/video_walls.php
node --test tests/video_wall_playback.cjs
bash tests/http_smoke.sh
bash tests/release_smoke.sh
```

`module_bootstrap.php` checks repeatable side-effect-free loading, module wiring,
App method visibility and private helper references, and static asset paths.
The HTTP suite covers API/form authentication, group access, filters, camera
management, settings, playback authorization and video walls. Browser playback
checks and their DVR fixture are described in [VIDEO-WALL-PLAYBACK.md](VIDEO-WALL-PLAYBACK.md).

The package, installer and updater already copy the complete `app/` tree.
`release_smoke.sh` builds and extracts the real package, loads all nested modules,
switches a temporary `current` symlink with shared state, and checks migration
and CLI backup/restore. It does not contact GitHub or run system services.
An optional previous Git ref, for example `bash tests/release_smoke.sh HEAD`
while working on the refactor, initializes state with that older code before
switching to the package under test. This verifies an actual monolith-to-modules
upgrade, not only repeated migration of the new release.

Deploy the complete release, not only `app/Portal.php`: the bootstrap depends on
the new files. The existing updater stages a full release, switches `current`,
runs migrations and schedules PHP-FPM reload to refresh opcode caches. This
structural refactor adds no database migration and no new runtime dependency.
