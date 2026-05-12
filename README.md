# SesamePortal

SesamePortal is a local PHP surveillance portal for SesameDVR installations. It
manages DVR servers, users, groups, cameras, per-user playback tokens, favorites,
and provides mosaic/map viewing pages backed by a SesameDVR auth backend.

## Local Development

```bash
php bin/portal migrate
php bin/portal create-admin admin admin123
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

Default local state is stored in `var/portal.sqlite`. Production installs use
`/var/lib/sesame-portal`.

## Production Install

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --admin-login admin \
  --admin-password 'change-me-now'
```

The installer creates an nginx site, configures php-fpm, initializes SQLite,
creates the first admin user, and can issue a Let's Encrypt certificate through
certbot.

## CLI

```bash
php bin/portal migrate
php bin/portal create-admin <login> <password>
php bin/portal rotate-tokens
```
