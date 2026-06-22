# Установка SesameDVR Trial для пользователей GitHub

Эта инструкция нужна, чтобы быстро развернуть SesameDVR рядом с SesamePortal и
подключить его как DVR-сервер для тестирования портала.

Получите персональный trial-ключ на странице
[Trial-версия SesameDVR](https://sesameware.com/ru/sesamedvr-trial/).

После подтверждения email вы получите письмо с персональным ключом и готовой
командой установки. Если при запросе ключа указать домен SesameDVR, команда уже
будет содержать параметры публикации через nginx и Let's Encrypt.

`bootstrap-trial-install.sh` - это удобный wrapper над protected bootstrap. Он
берёт текущий `protected/release` artifact из:

```text
https://license.sesameware.com/sesame-dvr-artifacts/protected/index.json
```

Отдельный `/sesame-dvr-artifacts/trial/` registry для этой команды не нужен.

## Требования к серверу

Поддерживаются:

- Ubuntu 22.04 / 24.04 / 26.04 или Debian 12 / 13;
- архитектура `amd64 / x86_64`;
- `systemd`;
- доступ `sudo`;
- исходящий HTTPS-доступ к:
  - `https://license.sesameware.com`;
  - `https://license-2.sesameware.com`.

Для публикации SesameDVR наружу нужны:

- открытый входящий TCP `80`;
- для HTTPS/Let's Encrypt также TCP `443`;
- DNS A/AAAA запись домена должна указывать на этот сервер до запуска
  установки.

## Быстрая установка

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh | sudo bash -s -- --license-key SDVR-TRIAL-XXXX-XXXX-XXXX-XXXX
```

## Установка с публичным HTTPS

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh \
  | sudo bash -s -- \
      --license-key SDVR-TRIAL-XXXX-XXXX-XXXX-XXXX \
      --publish-service \
      --publish-server-name dvr.example.com \
      --publish-acme \
      --acme-email admin@example.com
```

Замените:

- `dvr.example.com` на домен SesameDVR;
- `SDVR-TRIAL-XXXX-XXXX-XXXX-XXXX` на персональный ключ из письма;
- `admin@example.com` на email администратора для Let's Encrypt.

## Установка с публичным HTTP без сертификата

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh \
  | sudo bash -s -- \
      --license-key SDVR-TRIAL-XXXX-XXXX-XXXX-XXXX \
      --publish-service \
      --publish-server-name dvr.example.com
```

## Nginx и сертификаты

Nginx отдельно готовить не нужно: инсталлятор сам установит nginx, создаст
отдельный site-файл SesameDVR и подключит его.

Если nginx уже установлен и содержит другие сайты, укажите явный
`--publish-server-name <domain>`. Инсталлятор управляет только своим site-файлом
и не должен перезаписывать чужие nginx-конфиги.

При `--publish-acme` сертификат Let's Encrypt выпускается через `certbot` в
режиме `certonly --webroot`, чтобы certbot не переписывал nginx-конфиг.

## Что делает инсталлятор

Инсталлятор:

- устанавливает зависимости;
- устанавливает SesameDVR как `systemd` service;
- активирует trial-лицензию через license server;
- создаёт default DVR root `/var/dvr`;
- настраивает nginx для публичного доступа;
- проксирует приложение на внутренний service;
- отдаёт DVR-сегменты через nginx напрямую с диска;
- при `--publish-acme` выпускает Let's Encrypt сертификат;
- устанавливает service tools: `sesame-dvr-update`, `sesame-dvr-repair`,
  `sesame-dvr-license`, `sesame-dvr-storage`,
  `sesame-dvr-support-diagnostics`, `sesame-dvr-offline-install`;
- генерирует management token и выводит ссылку для первого входа.

## Первый вход

В конце установки будет напечатана ссылка вида:

```text
https://dvr.example.com/admin?token=...
```

Откройте её в браузере и задайте постоянный пароль администратора. Ссылку с
`token=...` считайте секретной: она даёт bootstrap-доступ к management UI.

Если UI запросит временный пароль при локальном доступе или при уже переданном
management token, используйте:

```text
admin / admin
```

После первого входа интерфейс потребует сменить пароль администратора.

## Важные пути

- приложение: `/opt/sesame-dvr/current`;
- конфигурация: `/var/lib/sesame-dvr/config.json`;
- license state: `/var/lib/sesame-dvr/license.json`;
- env/token: `/var/lib/sesame-dvr/sesame-dvr.env`;
- default архив видео: `/var/dvr/segments`;
- preview cache: `/var/dvr/previews`;
- ONVIF events: `/var/dvr/onvif-events`;
- дополнительные MultiVolume roots: задаются в `Настройки -> Тома хранения`;
- nginx site: `/etc/nginx/sites-available/sesame-dvr.conf`.

## Проверка установки

```bash
sudo systemctl status sesame-dvr --no-pager
sudo systemctl status nginx --no-pager
sudo nginx -t
sudo sesame-dvr-license status
sudo sesame-dvr-storage smoke
```

Для HTTPS:

```bash
sudo certbot certificates
```

## Обновление SesameDVR

```bash
sudo sesame-dvr-update
```

Если публичный доступ был настроен инсталлятором, updater автоматически
обновляет managed nginx site вместе с приложением, включая location'ы для
MultiVolume архива `/dvr/v/...`.

## Если установка не открывается снаружи

Проверьте:

- DNS домена указывает на IP сервера;
- firewall/cloud security group пропускает TCP `80` и `443`;
- `nginx -t` проходит без ошибок;
- `systemctl status sesame-dvr nginx` показывает active;
- playlist открывается, а segment URL из него возвращает `200 OK`.

## Подключение к SesamePortal

После установки добавьте SesameDVR в административном интерфейсе SesamePortal:

- `URL`: публичный URL SesameDVR, например `https://dvr.example.com`;
- `Management key`: management token SesameDVR;
- `Название`: удобное имя сервера.

Для камер можно использовать режим:

- `Полное управление на DVR`, если SesamePortal должен создавать и обновлять
  потоки на DVR;
- `Read-only`, если поток уже настроен на SesameDVR и портал должен только
  выдавать preview/playback-доступ.

## Что читать дальше

- руководство пользователя SesameDVR: `docs/sesame-dvr-user-guide.ru.md`;
- HTTP API SesameDVR: `docs/sesame-dvr-api.ru.md`;
- настройка DVR-хранилища: `docs/dvr-storage-configuration.ru.md`;
- описание возможностей SesameDVR: `docs/SESAME-DVR-PRODUCT-DESCRIPTION.ru.md`;
- Prometheus / OpenMetrics endpoint SesameDVR:
  `docs/prometheus-metrics-endpoint.ru.md`;
- BEAM hot patch manifest SesameDVR:
  `docs/beam-hot-patch-manifest.ru.md`;
- runbook DVR-side failover SesameDVR:
  `docs/dvr-cluster-failover-runbook.ru.md`;
- описание возможностей SesamePortal: `docs/SESAME-PORTAL-FEATURES.ru.md`.
