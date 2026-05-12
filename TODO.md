# SesamePortal TODO

## Phase 1: Installable MVP

- [x] Initialize repository and PHP project layout.
- [x] Add SQLite schema and migration command.
- [x] Add first-admin creation command.
- [x] Implement login/logout and session-based access control.
- [x] Implement users CRUD with password policy and block/unblock.
- [x] Implement groups CRUD with user membership.
- [x] Implement SesameDVR servers CRUD.
- [x] Implement cameras CRUD with server assignment, archive depth, OSM position,
  view direction, and group membership.
- [x] Implement managed/read-only camera modes.
- [x] Implement user favorites.
- [x] Implement mosaic viewer with group/favorites filters.
- [x] Implement OSM map viewer with group/favorites filters.
- [x] Implement SesameDVR auth-backend endpoint.
- [x] Implement daily token rotation with 6-hour overlap.
- [x] Implement optional static integration tokens.
- [x] Add mobile-friendly responsive CSS.
- [x] Add nginx/php-fpm/certbot installer.

## Phase 2: Hardening

- [x] Add automated HTTP/UI smoke tests.
- [x] Add full SesameDVR management sync error reporting per camera.
- [x] Add encrypted at-rest storage for SesameDVR management tokens with key
  rotation.
- [x] Add pagination/search for large user/group/camera lists.
- [x] Add richer dashboard metrics from all configured SesameDVR servers.
- [x] Add import/export backup commands.
- [x] Add structured audit log viewer.
- [x] Add stricter installer rollback/repair flow.
- [x] Add packaged release artifacts.

## Phase 3: Product Polish

- [x] Replace text logo with final Sesame brand asset if provided.
- [x] Add localized UI strings.
- [x] Add mobile player gestures and fullscreen refinements.
- [x] Add camera preview refresh scheduling.
- [x] Add optional MySQL/PostgreSQL adapter.
