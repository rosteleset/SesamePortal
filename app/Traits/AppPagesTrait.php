<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;
trait AppPagesTrait
{
    private static function dashboard(): void
    {
        Auth::requireAdmin();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            if ($action === 'refresh_server') {
                $server = Repo::server((int)Util::post('id'));
                $result = DvrClient::fetchServerMetrics((int)Util::post('id'));
                $message = self::dashboardRefreshNotice($result, $server['name'] ?? '');
            } elseif ($action === 'refresh_all') {
                $okCount = 0;
                $errorCount = 0;
                foreach (Repo::all('dvr_servers', 'name ASC') as $server) {
                    if ((int)$server['blocked'] === 0) {
                        $result = DvrClient::fetchServerMetrics((int)$server['id']);
                        $result['ok'] ? $okCount++ : $errorCount++;
                    }
                }
                $message = self::dashboardRefreshAllNotice($okCount, $errorCount);
            }
        }

        $counts = [
            self::t('dashboard.users', 'Пользователи') => (int)DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            self::t('dashboard.groups', 'Группы') => (int)DB::pdo()->query('SELECT COUNT(*) FROM portal_groups')->fetchColumn(),
            self::t('dashboard.cameras', 'Камеры') => (int)DB::pdo()->query('SELECT COUNT(*) FROM cameras')->fetchColumn(),
            self::t('dashboard.dvrServers', 'DVR серверы') => (int)DB::pdo()->query('SELECT COUNT(*) FROM dvr_servers')->fetchColumn(),
        ];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $recentSync = DB::pdo()->query("SELECT c.*, s.name AS server_name FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id ORDER BY COALESCE(c.last_sync_at, '') DESC, c.name ASC LIMIT 12")->fetchAll();

        self::layout(self::t('nav.dashboard', 'Dashboard'), function () use ($counts, $servers, $recentSync, $message) {
            self::notice($message);
            echo '<section class="summary-grid">';
            foreach ($counts as $label => $value) {
                echo '<div class="summary-card"><span>' . Util::h($label) . '</span><strong>' . Util::h($value) . '</strong></div>';
            }
            echo '</section>';
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('dashboard.dvrServersTitle', 'SesameDVR серверы') . '</h2>';
            self::smallPost('/admin/dashboard', ['action' => 'refresh_all'], self::t('action.updateAll', 'Обновить все'), 'primary');
            echo '</div><div class="server-grid">';
            foreach ($servers as $server) {
                self::serverMetricCard($server);
            }
            echo '</div></section>';
            self::table(self::t('dashboard.recentSync', 'Последняя синхронизация камер'), ['name', 'server_name', 'last_sync_ok', 'last_sync_at', 'last_sync_message'], $recentSync, '/admin/cameras', true, null, false);
        });
    }

    private static function dashboardRefreshNotice(array $result, string $serverName = ''): string
    {
        $prefix = $serverName !== '' ? $serverName . ': ' : '';
        if (!empty($result['ok'])) {
            return $prefix . self::t('dashboard.metricsUpdated', 'Статистика обновлена');
        }

        return $prefix . self::metricFailureNotice((string)($result['reason'] ?? ''), (string)($result['message'] ?? ''));
    }

    private static function dashboardRefreshAllNotice(int $okCount, int $errorCount): string
    {
        return self::t('dashboard.refreshFinished', 'Обновление завершено') . ': '
            . $okCount . ' ' . self::t('dashboard.refreshOk', 'успешно') . ', '
            . $errorCount . ' ' . self::t('dashboard.refreshErrors', 'с ошибкой');
    }

    private static function settings(): void
    {
        Auth::requireAdmin();
        $message = '';
        $messageClass = '';
        $updateResult = null;
        $forceCheck = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            if ($action === 'check_update') {
                $forceCheck = true;
            } elseif ($action === 'run_update') {
                $updateResult = PortalUpdateService::run();
                $forceCheck = !empty($updateResult['ok']);
                $message = !empty($updateResult['ok'])
                    ? self::t('settings.updateDone', 'Обновление Portal выполнено')
                    : self::t('settings.updateFailed', 'Обновление Portal не выполнено');
                $messageClass = !empty($updateResult['ok']) ? 'success' : 'danger';
            } elseif ($action === 'save_map_provider') {
                $provider = (string)Util::post('map_provider');
                $allowed = ['openstreetmap', 'yandex'];
                if (in_array($provider, $allowed, true)) {
                    DB::setSetting('map_provider', $provider);
                    $message = self::t('settings.mapProviderSaved', 'Поставщик карт сохранён');
                    $messageClass = 'success';
                } else {
                    $message = self::t('settings.mapProviderInvalid', 'Неверный поставщик карт');
                    $messageClass = 'danger';
                }
            } elseif ($action === 'save_map_view') {
                $lat = (string)Util::post('map_default_lat');
                $lng = (string)Util::post('map_default_lng');
                if (is_numeric($lat) && is_numeric($lng)
                    && (float)$lat >= -90 && (float)$lat <= 90
                    && (float)$lng >= -180 && (float)$lng <= 180) {
                    DB::setSetting('map_default_lat', (string)(float)$lat);
                    DB::setSetting('map_default_lng', (string)(float)$lng);
                    $message = self::t('settings.mapViewSaved', 'Координаты центра карты сохранены');
                    $messageClass = 'success';
                } else {
                    $message = self::t('settings.mapViewInvalid', 'Неверные координаты карты');
                    $messageClass = 'danger';
                }
            } elseif ($action === 'save_smtp') {
                $host = trim((string)Util::post('smtp_host'));
                $port = (int)Util::post('smtp_port', 465);
                $user = trim((string)Util::post('smtp_user'));
                $password = (string)Util::post('smtp_password');
                $security = (string)Util::post('smtp_security', 'ssl');
                $fromEmail = trim((string)Util::post('smtp_from_email'));
                $fromName = trim((string)Util::post('smtp_from_name'));
                if (!in_array($security, ['ssl', 'tls', ''], true) || $port < 1 || $port > 65535) {
                    $message = self::t('settings.smtpInvalid', 'Некорректные параметры SMTP');
                    $messageClass = 'danger';
                } else {
                    DB::setSetting('smtp_host', $host);
                    DB::setSetting('smtp_port', (string)$port);
                    DB::setSetting('smtp_user', $user);
                    if ($password !== '') {
                        DB::setSetting('smtp_password', Crypto::encrypt($password));
                    }
                    DB::setSetting('smtp_security', $security);
                    DB::setSetting('smtp_from_email', $fromEmail);
                    DB::setSetting('smtp_from_name', $fromName);
                    $message = self::t('settings.smtpSaved', 'SMTP-конфигурация сохранена');
                    $messageClass = 'success';
                }
            } elseif ($action === 'test_smtp') {
                $testEmail = trim((string)Util::post('smtp_test_email'));
                $host = (string)DB::setting('smtp_host', (string)Config::get('smtp_host', ''));
                if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $message = self::t('settings.smtpInvalid', 'Некорректные параметры SMTP');
                    $messageClass = 'danger';
                } elseif ($host === '') {
                    $message = self::t('settings.smtpNotConfigured', 'SMTP не настроен');
                    $messageClass = 'danger';
                } else {
                    $subject = self::t('settings.smtpTestSubject', 'Тестовое письмо SesamePortal');
                    $body = '<p>' . self::t('settings.smtpTestBody', 'Это тестовое письмо из SesamePortal. Если вы его получили, SMTP работает.') . '</p>';
                    $ok = Mail::send($testEmail, $subject, $body);
                    $message = $ok
                        ? self::t('settings.smtpTestOk', 'Тестовое письмо отправлено')
                        : self::t('settings.smtpTestFail', 'Не удалось отправить тестовое письмо');
                    $messageClass = $ok ? 'success' : 'danger';
                }
            }
        }

        $status = PortalUpdateService::status($forceCheck, true);
        if ($forceCheck && $updateResult === null) {
            $message = empty($status['checkError'])
                ? self::t('settings.checkDone', 'Проверка обновлений выполнена')
                : self::t('settings.checkFailed', 'Проверка обновлений не выполнена');
            $messageClass = empty($status['checkError']) ? 'success' : 'danger';
        }

        self::layout(self::t('settings.title', 'Настройки'), function () use ($message, $messageClass, $status, $updateResult) {
            self::notice($message, $messageClass);
            self::portalUpdatePanel($status, $updateResult);
            echo '<div class="map-settings-grid">';
            self::mapProviderPanel();
            self::mapViewPanel();
            echo '</div>';
            self::smtpSettingsPanel();
        });
    }

    private static function mapProviderPanel(): void
    {
        $current = Util::mapProvider();
        $providers = [
            'openstreetmap' => self::t('mapProvider.openstreetmap', 'OpenStreetMap'),
            'yandex' => self::t('mapProvider.yandex', 'Яндекс Карты'),
        ];

        echo '<section class="panel"><div class="section-head"><h2>' . self::t('settings.mapProvider', 'Поставщик карт') . '</h2><p class="muted">' . self::t('settings.mapProviderDesc', 'Выберите поставщика карт для просмотра карты') . '</p></div>';
        echo '<form method="post" action="/admin/settings">';
        echo '<input type="hidden" name="action" value="save_map_provider">';
        echo '<input type="hidden" name="csrf" value="' . Util::h(Csrf::token()) . '">';
        echo '<select name="map_provider" aria-label="' . Util::h(self::t('settings.mapProvider', 'Поставщик карт')) . '">';
        foreach ($providers as $key => $label) {
            echo '<option value="' . Util::h($key) . '"' . ($key === $current ? ' selected' : '') . '>' . Util::h($label) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="primary">' . self::t('action.save', 'Сохранить') . '</button>';
        echo '</form></section>';
    }

    private static function mapViewPanel(): void
    {
        $view = Util::mapDefaultView();
        echo '<section class="panel"><div class="section-head"><h2>' . self::t('settings.mapView', 'Центр карты по умолчанию') . '</h2><p class="muted">' . self::t('settings.mapViewDesc', 'Координаты, отображаемые при открытии карты') . '</p></div>';
        echo '<form method="post" action="/admin/settings">';
        echo '<input type="hidden" name="action" value="save_map_view">';
        echo '<input type="hidden" name="csrf" value="' . Util::h(Csrf::token()) . '">';
        echo '<div class="form-row">';
        echo '<label>' . self::t('settings.mapViewLat', 'Широта') . '<input type="text" name="map_default_lat" value="' . Util::h((string)$view['lat']) . '"></label>';
        echo '<label>' . self::t('settings.mapViewLng', 'Долгота') . '<input type="text" name="map_default_lng" value="' . Util::h((string)$view['lng']) . '"></label>';
        echo '</div>';
        echo '<button type="submit" class="primary">' . self::t('action.save', 'Сохранить') . '</button>';
        echo '</form></section>';
    }

    private static function smtpSettingsPanel(): void
    {
        $host = (string)DB::setting('smtp_host', (string)Config::get('smtp_host', ''));
        $port = (string)DB::setting('smtp_port', (string)Config::get('smtp_port', 465));
        if ($port === '') {
            $port = '465';
        }
        $user = (string)DB::setting('smtp_user', (string)Config::get('smtp_user', ''));
        $security = (string)DB::setting('smtp_security', (string)Config::get('smtp_security', 'ssl'));
        $fromEmail = (string)DB::setting('smtp_from_email', (string)Config::get('smtp_from_email', ''));
        $fromName = (string)DB::setting('smtp_from_name', (string)Config::get('smtp_from_name', 'SesamePortal'));
        if ($fromName === '') {
            $fromName = 'SesamePortal';
        }
        $hasPassword = (string)DB::setting('smtp_password', '') !== '';

        echo '<section class="panel"><div class="section-head"><h2>' . self::t('settings.smtp', 'SMTP') . '</h2><p class="muted">' . self::t('settings.smtpDesc', 'Настройки отправки писем (восстановление пароля). Хранятся в БД.') . '</p></div>';

        echo '<form method="post" action="/admin/settings">';
        echo '<input type="hidden" name="action" value="save_smtp">';
        echo '<input type="hidden" name="csrf" value="' . Util::h(Csrf::token()) . '">';
        echo '<div class="form-row">';
        echo '<label>' . self::t('settings.smtpHost', 'SMTP-сервер') . '<input type="text" name="smtp_host" value="' . Util::h($host) . '" placeholder="smtp.example.com"></label>';
        echo '<label>' . self::t('settings.smtpPort', 'Порт') . '<input type="number" name="smtp_port" min="1" max="65535" value="' . Util::h($port) . '"></label>';
        echo '</div>';
        echo '<div class="form-row">';
        echo '<label>' . self::t('settings.smtpUser', 'Пользователь') . '<input type="text" name="smtp_user" value="' . Util::h($user) . '"></label>';
        echo '<label>' . self::t('settings.smtpPassword', 'Пароль') . '<input type="password" name="smtp_password" autocomplete="new-password" placeholder="' . ($hasPassword ? self::t('settings.smtpPasswordPlaceholder', 'оставьте пустым, чтобы не менять') : self::t('settings.smtpPasswordEmpty', 'не задан')) . '"></label>';
        echo '</div>';
        echo '<div class="form-row">';
        echo '<label>' . self::t('settings.smtpSecurity', 'Шифрование') . '<select name="smtp_security">';
        $secOptions = [
            'ssl' => self::t('settings.smtpSecuritySsl', 'SSL'),
            'tls' => self::t('settings.smtpSecurityTls', 'TLS'),
            '' => self::t('settings.smtpSecurityNone', 'Без шифрования'),
        ];
        foreach ($secOptions as $key => $label) {
            echo '<option value="' . Util::h($key) . '"' . ($key === $security ? ' selected' : '') . '>' . Util::h($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>' . self::t('settings.smtpFromEmail', 'Email отправителя') . '<input type="text" name="smtp_from_email" value="' . Util::h($fromEmail) . '"></label>';
        echo '</div>';
        echo '<div class="form-row">';
        echo '<label>' . self::t('settings.smtpFromName', 'Имя отправителя') . '<input type="text" name="smtp_from_name" value="' . Util::h($fromName) . '"></label>';
        echo '</div>';
        echo '<div class="form-actions">';
        echo '<button type="submit" class="primary">' . self::t('action.save', 'Сохранить') . '</button>';
        echo '</div>';
        echo '</form>';

        echo '<form method="post" action="/admin/settings" style="margin-top:14px">';
        echo '<input type="hidden" name="action" value="test_smtp">';
        echo '<input type="hidden" name="csrf" value="' . Util::h(Csrf::token()) . '">';
        echo '<div class="form-row">';
        echo '<label>' . self::t('settings.smtpTestEmail', 'Email для теста') . '<input type="text" name="smtp_test_email" placeholder="user@example.com"></label>';
        echo '<button type="submit">' . self::t('settings.smtpTest', 'Отправить тестовое письмо') . '</button>';
        echo '</div>';
        echo '</form>';

        echo '</section>';
    }

    private static function portalUpdatePanel(array $status, ?array $updateResult = null): void
    {
        $current = is_array($status['current'] ?? null) ? $status['current'] : [];
        $latest = is_array($status['latest'] ?? null) ? $status['latest'] : [];
        $badge = self::portalUpdateBadge($status);

        echo '<section class="panel portal-update-panel"><div class="section-head"><div><h2>' . self::t('settings.portalUpdates', 'Обновления Portal') . '</h2><p class="muted">' . Util::h(self::t('settings.updateHint', 'Portal сравнивает текущую сборку с последним commit выбранной ветки GitHub.')) . '</p></div>';
        echo '<span class="pill ' . Util::h($badge['class']) . '">' . Util::h($badge['text']) . '</span></div>';

        echo '<div class="portal-version-grid">';
        self::portalVersionCard(self::t('settings.currentVersion', 'Текущая версия'), $current);
        self::portalVersionCard(self::t('settings.githubVersion', 'Доступная версия на GitHub'), $latest);
        echo '</div>';

        echo '<dl class="portal-update-meta">';
        echo '<dt>' . self::t('settings.githubRepo', 'GitHub repository') . '</dt><dd><code>' . Util::h((string)($status['repo'] ?? '')) . '</code></dd>';
        echo '<dt>' . self::t('settings.githubRef', 'GitHub branch/ref') . '</dt><dd><code>' . Util::h((string)($status['ref'] ?? '')) . '</code></dd>';
        echo '<dt>' . self::t('settings.checkedAt', 'Проверено') . '</dt><dd>' . self::portalUpdateValue($status['checkedAt'] ?? null) . '</dd>';
        echo '<dt>' . self::t('settings.updateTool', 'Update tool') . '</dt><dd>' . ((bool)($status['toolInstalled'] ?? false) ? self::t('settings.toolInstalled', 'установлен') : self::t('settings.toolMissing', 'не установлен')) . '</dd>';
        if (!empty($status['checkError'])) {
            echo '<dt>' . self::t('settings.checkError', 'Ошибка проверки') . '</dt><dd class="danger-text">' . Util::h((string)$status['checkError']) . '</dd>';
        }
        echo '</dl>';

        echo '<div class="form-actions portal-update-actions">';
        self::smallPost('/admin/settings', ['action' => 'check_update'], self::t('settings.checkUpdates', 'Проверить обновления'));
        if ((bool)($status['enabled'] ?? false) && (bool)($status['toolInstalled'] ?? false) && (bool)($status['updateAvailable'] ?? false)) {
            self::smallPost(
                '/admin/settings',
                ['action' => 'run_update'],
                self::t('settings.installUpdate', 'Обновить Portal'),
                'primary',
                self::t('settings.updateConfirm', 'Обновить код Portal из GitHub и выполнить миграции?')
            );
        } else {
            $disabledReason = !(bool)($status['enabled'] ?? false)
                ? self::t('settings.updateDisabled', 'обновления отключены')
                : (!(bool)($status['toolInstalled'] ?? false)
                    ? self::t('settings.toolMissing', 'не установлен')
                    : self::t('settings.noUpdateAvailable', 'нет доступного обновления'));
            echo '<button type="button" disabled title="' . Util::h($disabledReason) . '">' . self::icon('download') . self::t('settings.installUpdate', 'Обновить Portal') . '</button>';
        }
        echo '</div>';

        if ($updateResult !== null) {
            $summary = !empty($updateResult['ok'])
                ? self::t('settings.updateOutputOk', 'Вывод updater')
                : self::t('settings.updateOutputFailed', 'Вывод updater с ошибкой');
            echo '<details class="technical-result" open><summary>' . Util::h($summary) . '</summary><pre>' . Util::h((string)($updateResult['output'] ?? '')) . '</pre></details>';
        }

        echo '</section>';
    }

    private static function portalUpdateBadge(array $status): array
    {
        if (!(bool)($status['enabled'] ?? false)) {
            return ['class' => 'warn', 'text' => self::t('settings.updateDisabled', 'обновления отключены')];
        }
        if (!empty($status['checkError']) && empty($status['latest'])) {
            return ['class' => 'danger', 'text' => self::t('settings.checkFailed', 'проверка не выполнена')];
        }
        if ((bool)($status['updateAvailable'] ?? false)) {
            return ['class' => 'warn', 'text' => self::t('settings.updateAvailable', 'доступно обновление')];
        }
        if ((bool)($status['upToDate'] ?? false)) {
            return ['class' => 'success', 'text' => self::t('settings.upToDate', 'актуально')];
        }
        return ['class' => 'info', 'text' => self::t('settings.notChecked', 'не проверено')];
    }

    private static function portalVersionCard(string $title, array $release): void
    {
        $version = self::portalUpdateVersionLabel($release);
        $commit = self::shortCommit((string)($release['sourceCommit'] ?? ''));
        $date = (string)($release['commitDate'] ?? $release['builtAt'] ?? '');
        echo '<div class="summary-card portal-version-card"><span>' . Util::h($title) . '</span><strong>' . Util::h($version) . '</strong>';
        if ($commit !== '') {
            echo '<code>' . Util::h($commit) . '</code>';
        }
        if ($date !== '') {
            echo '<small>' . Util::h($date) . '</small>';
        }
        if (!empty($release['message'])) {
            echo '<p>' . Util::h((string)$release['message']) . '</p>';
        }
        echo '</div>';
    }

    private static function portalUpdateVersionLabel(array $release): string
    {
        $version = trim((string)($release['version'] ?? ''));
        if ($version !== '') {
            return $version;
        }
        $commit = self::shortCommit((string)($release['sourceCommit'] ?? ''));
        return $commit !== '' ? $commit : self::t('settings.versionUnknown', 'неизвестно');
    }

    private static function portalUpdateValue(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        return $text !== '' ? Util::h($text) : '<span class="muted">' . self::t('settings.notChecked', 'не проверено') . '</span>';
    }

    private static function shortCommit(string $sha): string
    {
        $sha = trim($sha);
        return preg_match('/^[A-Fa-f0-9]{7,40}$/', $sha) ? substr($sha, 0, 12) : '';
    }

    private static function serverCheckNotice(array $result, string $serverName = ''): string
    {
        $prefix = $serverName !== '' ? $serverName . ': ' : '';
        if (!empty($result['ok'])) {
            return $prefix . self::t('server.checkOk', 'Проверка сервера выполнена');
        }

        return $prefix . self::metricFailureNotice((string)($result['reason'] ?? ''), (string)($result['message'] ?? ''));
    }

    private static function metricFailureNotice(string $reason, string $message): string
    {
        if ($reason === 'management_token_missing') {
            return self::t('server.managementTokenMissingNotice', 'Management token не указан. Portal не может прочитать /api/system/status и /api/streams этого SesameDVR сервера.');
        }
        if ($reason === 'management_token_unreadable') {
            return self::t('server.managementTokenUnreadableNotice', 'Management token не удалось расшифровать. Сохраните новый token в настройках DVR сервера.');
        }
        if (preg_match('/^HTTP\s+401\b/', $message) || str_contains($message, 'HTTP 401')) {
            return self::t('server.managementUnauthorizedNotice', 'SesameDVR вернул HTTP 401. Проверьте Management token в настройках DVR сервера.');
        }

        return self::t('dashboard.metricsRefreshFailed', 'Статистика не обновлена. Подробности показаны в карточке сервера.');
    }

    private static function login(): void
    {
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Auth::login((string)Util::post('login'), (string)Util::post('password'), Util::checkbox('remember_me') === 1)) {
                Util::redirect('/');
            }
            $error = self::t('login.invalid', 'Неверный логин или пароль');
        }

        self::layout(self::t('login.title', 'Вход'), function () use ($error) {
            echo '<section class="login-visual"><div><img src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"><p>' . self::t('login.subtitle', 'Портал видеонаблюдения SesameWare') . '</p></div>';
            echo '<div class="login-features"><span>' . Util::h(self::t('login.feature.secure', 'Безопасно')) . '</span><span>' . Util::h(self::t('login.feature.reliable', 'Надежно')) . '</span><span>' . Util::h(self::t('login.feature.efficient', 'Производительно')) . '</span></div></section>';
            echo '<section class="login-panel login-card">';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" autocomplete="username" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" autocomplete="current-password" required></label>';
            echo '<label class="check"><input type="checkbox" name="remember_me" value="1"> ' . Util::h(self::t('login.rememberMe', 'Запомнить меня')) . '</label>';
            echo '<button class="primary">' . self::t('action.login', 'Войти') . '</button>';
            echo '</form>';
            echo '<a href="/forgot" class="forgot-link">' . Util::h(self::t('auth.forgotPassword', 'Забыли пароль?')) . '</a>';
            echo I18n::languageLinks() . '</section>';
        }, null);
    }

    private static function logout(): void
    {
        Auth::logout();
        Util::redirect('/login');
    }

    private static function onboarding(): void
    {
        $user = Auth::requireLogin();
        if ((int)($user['must_change_password'] ?? 0) !== 1) {
            Util::redirect('/');
        }
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $newPassword = (string)Util::post('new_password');
            $confirmPassword = (string)Util::post('confirm_password');
            $email = trim((string)Util::post('email'));
            if (strlen($newPassword) < 6) {
                $error = self::t('auth.passwordShort', 'Пароль должен быть не короче 6 символов');
            } elseif ($newPassword !== $confirmPassword) {
                $error = self::t('auth.passwordMismatch', 'Пароли не совпадают');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = self::t('auth.emailInvalid', 'Введите корректный email');
            } else {
                DB::pdo()->prepare('UPDATE users SET password_hash=?, email=?, must_change_password=0 WHERE id=?')
                    ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $email, (int)$user['id']]);
                Audit::logForUser((int)$user['id'], 'user.onboarding', 'password changed, email set');
                Util::redirect('/');
            }
        }
        self::layout(self::t('auth.onboardingTitle', 'Смена пароля'), function () use ($error, $user): void {
            echo '<div class="onboarding-overlay">';
            echo '<div class="onboarding-modal">';
            echo '<h2>' . Util::h(self::t('auth.onboardingTitle', 'Смена пароля')) . '</h2>';
            echo '<p class="onboarding-intro">' . Util::h(self::t('auth.onboardingRequired', 'При первом входе необходимо сменить пароль и указать email для восстановления')) . '</p>';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>' . self::t('auth.newPassword', 'Новый пароль') . '<input name="new_password" type="password" required minlength="6" autocomplete="new-password"></label>';
            echo '<label>' . self::t('auth.confirmPassword', 'Подтверждение') . '<input name="confirm_password" type="password" required autocomplete="new-password"></label>';
            echo '<label>' . self::t('auth.email', 'Email') . '<input name="email" type="email" required autocomplete="email" placeholder="user@example.com"></label>';
            echo '<button type="submit" class="primary">' . self::t('action.save', 'Сохранить') . '</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        });
    }

    private static function forgotPassword(): void
    {
        $message = '';
        $messageClass = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $email = trim((string)Util::post('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = self::t('auth.emailInvalid', 'Введите корректный email');
                $messageClass = 'danger';
            } else {
                $stmt = DB::pdo()->prepare('SELECT id, login FROM users WHERE email = ? AND blocked = 0');
                $stmt->execute([$email]);
                $u = $stmt->fetch();
                if ($u) {
                    $token = Util::randomToken();
                    $expires = date('Y-m-d\TH:i:sP', time() + 3600);
                    DB::pdo()->prepare('UPDATE users SET password_reset_token=?, password_reset_expires=? WHERE id=?')
                        ->execute([$token, $expires, (int)$u['id']]);
                    $baseUrl = rtrim((string)Config::get('base_url', ''), '/');
                    $resetLink = $baseUrl . '/reset?token=' . $token;
                    $subject = self::t('auth.resetEmailSubject', 'Восстановление пароля SesamePortal');
                    $body = '<p>' . Util::h(self::t('auth.resetEmailBody', 'Для сброса пароля перейдите по ссылке:')) . '</p>';
                    $body .= '<p><a href="' . $resetLink . '">' . $resetLink . '</a></p>';
                    $body .= '<p>' . Util::h(self::t('auth.resetEmailExpire', 'Ссылка действительна 1 час.')) . '</p>';
                    Mail::send($email, $subject, $body);
                    Audit::logForUser((int)$u['id'], 'user.password_reset_requested', 'email=' . Audit::cleanValue($email));
                } else {
                    usleep(1500000);
                }
                $message = self::t('auth.resetSent', 'Письмо отправлено');
                $messageClass = 'success';
            }
        }
        self::layout(self::t('auth.forgotPassword', 'Забыли пароль?'), function () use ($message, $messageClass): void {
            echo '<section class="login-panel login-card forgot-form">';
            echo '<h2>' . Util::h(self::t('auth.forgotPassword', 'Забыли пароль?')) . '</h2>';
            echo '<p>' . Util::h(self::t('auth.forgotInstructions', 'Введите email — пришлём ссылку для сброса пароля')) . '</p>';
            self::notice($message, $messageClass);
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>' . self::t('auth.email', 'Email') . '<input name="email" type="email" required autocomplete="email"></label>';
            echo '<button type="submit" class="primary">' . self::t('action.send', 'Отправить') . '</button>';
            echo '</form>';
            echo '<a href="/login" class="forgot-back">' . Util::h(self::t('action.back', 'Назад')) . '</a>';
            echo '</section>';
        }, null);
    }

    private static function resetPassword(): void
    {
        $token = (string)($_GET['token'] ?? '');
        $error = '';
        $valid = false;
        $stmt = DB::pdo()->prepare('SELECT id, login, password_reset_expires FROM users WHERE password_reset_token = ? AND blocked = 0');
        $stmt->execute([$token]);
        $u = $stmt->fetch();
        if (!$u) {
            $error = self::t('auth.resetInvalidToken', 'Неверная ссылка сброса');
        } elseif ($u['password_reset_expires'] && strtotime((string)$u['password_reset_expires']) < time()) {
            $error = self::t('auth.resetExpired', 'Ссылка истекла');
        } else {
            $valid = true;
        }
        if ($valid && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $newPassword = (string)Util::post('new_password');
            $confirmPassword = (string)Util::post('confirm_password');
            if (strlen($newPassword) < 6) {
                $error = self::t('auth.passwordShort', 'Пароль должен быть не короче 6 символов');
            } elseif ($newPassword !== $confirmPassword) {
                $error = self::t('auth.passwordMismatch', 'Пароли не совпадают');
            } else {
                DB::pdo()->prepare('UPDATE users SET password_hash=?, password_reset_token=NULL, password_reset_expires=NULL WHERE id=?')
                    ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$u['id']]);
                Audit::logForUser((int)$u['id'], 'user.password_reset', 'password changed via reset');
                Util::redirect('/login');
            }
        }
        self::layout(self::t('auth.resetPassword', 'Сброс пароля'), function () use ($error, $valid): void {
            echo '<section class="login-panel login-card forgot-form">';
            echo '<h2>' . Util::h(self::t('auth.resetPassword', 'Сброс пароля')) . '</h2>';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            if ($valid) {
                echo '<form method="post" class="form">';
                echo Csrf::field();
                echo '<label>' . self::t('auth.newPassword', 'Новый пароль') . '<input name="new_password" type="password" required minlength="6" autocomplete="new-password"></label>';
                echo '<label>' . self::t('auth.confirmPassword', 'Подтверждение') . '<input name="confirm_password" type="password" required autocomplete="new-password"></label>';
                echo '<button type="submit" class="primary">' . self::t('action.save', 'Сохранить') . '</button>';
                echo '</form>';
            }
            echo '<a href="/login" class="forgot-back">' . Util::h(self::t('action.back', 'Назад')) . '</a>';
            echo '</section>';
        }, null);
    }

    private static function users(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        $messageClass = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);

            if ($action === 'save') {
                $login = trim((string)Util::post('login'));
                $email = trim((string)Util::post('email'));
                $password = (string)Util::post('password');
                $role = Util::post('role') === 'admin' ? 'admin' : 'user';
                $blocked = Util::checkbox('blocked');
                $hideArchive = Util::checkbox('hide_archive');
                $mosaicEnabled = Util::checkbox('mosaic_enabled');
                $canRenameCameras = Util::checkbox('can_rename_cameras');
                $beforeUser = $id > 0 ? self::rowById('users', $id) : null;
                $mustChangePassword = $id === 0
                    ? ($role === 'user' ? 1 : 0)
                    : (int)($beforeUser['must_change_password'] ?? 0);
                $generatedPassword = '';
                $adminComment = trim((string)Util::post('admin_comment'));
                $beforeFolderIds = $id > 0 ? self::linkedIds('user_folders', 'user_id', $id, 'folder_id') : [];
                $folderIds = self::formIntArray('folder_ids_json', 'folder_ids');
                $missingFolderIds = self::missingIds('group_folders', $folderIds);
                if ($missingFolderIds !== []) {
                    $message = 'Selected folders contain unknown id(s): ' . implode(', ', $missingFolderIds);
                } elseif ($login === '') {
                    $message = self::t('users.loginRequired', 'Логин обязателен');
                } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = self::t('auth.emailInvalid', 'Введите корректный email');
                } elseif ($email !== '' && self::emailTakenByOther($email, $id)) {
                    $message = self::t('auth.emailInUse', 'Этот email уже занят другим пользователем');
                } else {
                    if ($id === 0 && $password === '' && $role === 'user') {
                        $generatedPassword = 'Sesame' . random_int(1000, 9999) . '!';
                        $password = $generatedPassword;
                    }
                    if ($id === 0 && strlen($password) < 6) {
                        $message = self::t('users.passwordShort', 'Пароль должен быть не короче 6 символов');
                    } elseif ($id > 0) {
                        if ($password !== '') {
                            if (strlen($password) < 6) {
                                $message = self::t('users.passwordShort', 'Пароль должен быть не короче 6 символов');
                            } else {
                                $pdo->prepare('UPDATE users SET login=?, email=?, password_hash=?, role=?, blocked=?, hide_archive=?, mosaic_enabled=?, can_rename_cameras=?, must_change_password=?, admin_comment=? WHERE id=?')
                                    ->execute([$login, $email, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $mosaicEnabled, $canRenameCameras, $mustChangePassword, $adminComment, $id]);
                            }
                        } else {
                            $pdo->prepare('UPDATE users SET login=?, email=?, role=?, blocked=?, hide_archive=?, mosaic_enabled=?, can_rename_cameras=?, must_change_password=?, admin_comment=? WHERE id=?')
                                ->execute([$login, $email, $role, $blocked, $hideArchive, $mosaicEnabled, $canRenameCameras, $mustChangePassword, $adminComment, $id]);
                        }
                    } else {
                        $pdo->prepare('INSERT INTO users(login, email, password_hash, role, blocked, hide_archive, mosaic_enabled, can_rename_cameras, must_change_password, admin_comment, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$login, $email, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $mosaicEnabled, $canRenameCameras, $mustChangePassword, $adminComment, Util::randomToken(), TokenService::today(), Util::now()]);
                        $id = DB::lastInsertId('users');
                    }
                    if ($message === '') {
                        self::replaceLinks('user_folders', 'user_id', $id, 'folder_id', $folderIds);
                        $afterUser = self::rowById('users', $id) ?: ['login' => $login, 'role' => $role, 'blocked' => $blocked, 'hide_archive' => $hide_archive];
                        $afterFolderIds = self::linkedIds('user_folders', 'user_id', $id, 'folder_id');
                        self::logUserSaveAudit(null, $id, $beforeUser, $afterUser, $beforeFolderIds, $afterFolderIds);
                        $message = self::t('users.saveDone', 'Пользователь сохранён');
                        if ($generatedPassword !== '') {
                            $message .= ' ' . self::t('users.defaultPasswordGenerated', 'Временный пароль') . ': ' . $generatedPassword;
                        }
                        $messageClass = 'success';
                    }
                }
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                Audit::log('user.delete', 'user_id=' . $id);
            } elseif ($action === 'issue_static' && $id > 0) {
                TokenService::issueStaticToken($id);
            } elseif ($action === 'revoke_static' && $id > 0) {
                TokenService::revokeStaticToken($id);
            }
        }

        $edit = self::rowById('users', (int)($_GET['edit'] ?? 0));
        $linkedFolders = $edit ? self::linkedIds('user_folders', 'user_id', (int)$edit['id'], 'folder_id') : [];
        $folders = Repo::allFolders();
        $list = self::filteredUsers();
        $users = $list['rows'];
        self::layout(self::t('users.title', 'Пользователи'), function () use ($users, $edit, $folders, $linkedFolders, $message, $messageClass, $list) {
            self::notice($message, $messageClass);
            echo '<div class="user-admin-stack">';
            echo '<details class="panel user-create-panel"' . ($edit ? ' open' : '') . '>';
            echo '<summary><h2>' . ($edit ? self::t('users.edit', 'Изменить пользователя') : self::t('users.new', 'Новый пользователь')) . '</h2></summary>';
            $savingLabel = self::t('users.saving', 'Сохраняем пользователя...');
            echo '<form method="post" class="form" data-submit-progress="' . Util::h($savingLabel) . '">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" value="' . Util::h($edit['login'] ?? '') . '" required></label>';
            echo '<label>' . self::t('auth.email', 'Email') . '<input name="email" type="email" value="' . Util::h($edit['email'] ?? '') . '" placeholder="user@example.com"></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" minlength="6" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : self::t('users.passwordPlaceholderNew', 'минимум 6 символов')) . '"></label>';
            if ($edit) {
                $editHasToken = trim((string)($edit['static_token_hash'] ?? '')) !== '';
                echo '<div class="static-token-row">';
                echo '<div class="static-token-head">';
                echo '<span class="static-token-label">' . Util::h(self::t('users.staticToken', 'Постоянный токен пользователя')) . '</span>';
                echo '<span class="pill ' . ($editHasToken ? 'success' : 'danger') . '">' . Util::h($editHasToken ? self::t('token.staticPresent', 'есть') : self::t('token.staticMissing', 'нет')) . '</span>';
                echo '</div>';
                if ($editHasToken) {
                    $revealLabel = self::t('token.staticReveal', 'Показать и скопировать');
                    echo '<span class="static-token-field">';
                    echo '<input class="static-token-input" type="text" readonly value="*******" aria-label="' . Util::h($revealLabel) . '" data-static-token-reveal data-static-token-user="' . (int)$edit['id'] . '">';
                    echo '<button type="button" class="icon-action static-token-copy" data-static-token-reveal data-static-token-user="' . (int)$edit['id'] . '" title="' . Util::h($revealLabel) . '" aria-label="' . Util::h($revealLabel) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg></button>';
                    echo '</span>';
                }
                echo '<span class="static-token-actions">';
                self::smallPostButton(
                    'static-token-issue-form',
                    $editHasToken ? self::t('token.staticReplace', 'Заменить статический токен') : self::t('token.staticIssue', 'Выпустить статический токен'),
                    '',
                    $editHasToken ? 'token-refresh' : 'token-issue'
                );
                if ($editHasToken) {
                    self::smallPostButton('static-token-revoke-form', self::t('action.revoke', 'Отозвать'), 'danger', 'token-revoke');
                }
                echo '</span></div>';
            }
            echo '<label>' . self::t('column.role', 'Роль') . '<select name="role"><option value="user">user</option><option value="admin" ' . (($edit['role'] ?? '') === 'admin' ? 'selected' : '') . '>admin</option></select></label>';
            echo '<label>' . self::t('users.adminComment', 'Комментарий администратора') . '<textarea name="admin_comment" rows="3">' . Util::h($edit['admin_comment'] ?? '') . '</textarea></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('users.blocked', 'Заблокирован') . '</label>';
            echo '<label class="check"><input type="checkbox" name="hide_archive" ' . (!empty($edit['hide_archive']) ? 'checked' : '') . '> ' . self::t('users.hideArchive', 'Скрывать архив') . '</label>';
            echo '<label class="check"><input type="checkbox" name="mosaic_enabled" ' . (!empty($edit['mosaic_enabled']) ? 'checked' : '') . '> ' . self::t('users.mosaicEnabled', 'Разрешить создание мозаик') . '</label>';
            echo '<label class="check"><input type="checkbox" name="can_rename_cameras" ' . (!empty($edit['can_rename_cameras']) ? 'checked' : '') . '> ' . self::t('users.canRenameCameras', 'Разрешить переименование камер') . '</label>';
            self::folderCheckboxTree(self::t('folders.title', 'Папки'), 'folder_ids[]', $folders, $linkedFolders, 'folder_ids_json');
            echo '<div class="form-submit-row"><button type="submit" class="primary" data-submit-button>' . self::t('action.save', 'Сохранить') . '</button><div class="submit-progress" data-submit-status hidden role="status" aria-live="polite">' . Util::h($savingLabel) . '</div></div></form>';
            if ($edit) {
                $editHasToken = trim((string)($edit['static_token_hash'] ?? '')) !== '';
                self::smallPostFormOpen(
                    'static-token-issue-form',
                    '/admin/users',
                    ['action' => 'issue_static', 'id' => (int)$edit['id']],
                    $editHasToken ? self::t('token.staticReplaceConfirm', 'Старый статический токен сразу перестанет работать. Выпустить новый токен?') : ''
                );
                self::smallPostFormClose();
                if ($editHasToken) {
                    self::smallPostFormOpen('static-token-revoke-form', '/admin/users', ['action' => 'revoke_static', 'id' => (int)$edit['id']]);
                    self::smallPostFormClose();
                }
            }
            echo '</details>';
            self::table(self::t('users.title', 'Пользователи'), ['login', 'role', 'admin_comment', 'blocked', 'hide_archive', 'static_token_hash', 'last_login_at'], $users, '/admin/users', false, $list);
            echo '</div>';
        });
    }

    /**
     * Группы — дерево контейнеров для папок.
     *
     * Родительская группа (parent_group_id):
     *   - Связывает группы в иерархию: дочерняя группа ссылается на родительскую.
     *   - Нужна для визуального отображения пути в дереве («Родитель / Группа»)
     *     и для фильтра group:* в API (разворачивает все папки ветки группы).
     *   - При удалении родителя дочерние группы переносятся на верхний уровень (SET NULL).
     *   - Права НЕ наследуются по дереву: доступ к папке дочерней группы не даёт
     *     доступа к папкам родительской — каждая папка выдаётся явно.
     *   - UI не позволяет выбрать родителем саму группу или её потомка (защита от циклов).
     */
    private static function groups(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $name = trim((string)Util::post('name'));
                $current = $id > 0 ? self::rowById('portal_groups', $id) : null;
                $parentId = self::groupParentIdFromInput(['parent_group_id' => Util::post('parent_group_id')], $current);
                $parentError = self::groupParentValidationError($id, $parentId);
                if ($name === '') {
                    $message = self::t('groups.nameRequired', 'Название группы обязательно');
                } elseif ($parentError !== '') {
                    $message = $parentError;
                } elseif ($id > 0) {
                    $pdo->prepare('UPDATE portal_groups SET parent_group_id=?, name=?, description=?, blocked=? WHERE id=?')
                        ->execute([$parentId, $name, Util::post('description'), Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO portal_groups(parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([$parentId, $name, Util::post('description'), Util::checkbox('blocked'), Util::now()]);
                    $id = DB::lastInsertId('portal_groups');
                }
                if ($message === '') {
                    Audit::log('group.save', $name . ' parent_group_id=' . ($parentId ?? 'none'));
                }
            } elseif ($action === 'delete' && $id > 0) {
                $group = self::rowById('portal_groups', $id);
                if (!$group) {
                    $message = self::t('groups.deleteMissing', 'Группа уже удалена или не найдена');
                } elseif (Util::checkbox('confirm_delete') !== 1) {
                    $message = self::t('groups.deleteConfirmRequired', 'Подтвердите удаление группы');
                } else {
                    $pdo->prepare('UPDATE portal_groups SET parent_group_id = NULL WHERE parent_group_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM portal_groups WHERE id=?')->execute([$id]);
                    $message = self::t('groups.deleteDone', 'Группа удалена');
                    Audit::log('group.delete', 'group_id=' . $id . ' name=' . (string)$group['name']);
                }
            } elseif ($action === 'save_folder') {
                $groupId = (int)Util::post('group_id', 0);
                $folderId = (int)Util::post('folder_id', 0);
                $folderName = trim((string)Util::post('folder_name'));
                if ($groupId <= 0 || !self::rowById('portal_groups', $groupId)) {
                    $message = self::t('groups.deleteMissing', 'Группа уже удалена или не найдена');
                } elseif ($folderName === '') {
                    $message = self::t('folders.nameRequired', 'Название папки обязательно');
                } elseif ($folderId > 0) {
                    $pdo->prepare('UPDATE group_folders SET name=?, description=?, blocked=? WHERE id=? AND group_id=?')
                        ->execute([$folderName, Util::post('folder_description'), Util::checkbox('folder_blocked'), $folderId, $groupId]);
                    Audit::log('folder.save', 'folder_id=' . $folderId . ' group_id=' . $groupId);
                } else {
                    $pdo->prepare('INSERT INTO group_folders(group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([$groupId, $folderName, Util::post('folder_description'), Util::checkbox('folder_blocked'), Util::now()]);
                    Audit::log('folder.save', 'group_id=' . $groupId . ' name=' . $folderName);
                }
            } elseif ($action === 'delete_folder' && $id > 0) {
                $groupId = (int)Util::post('group_id', 0);
                $pdo->prepare('DELETE FROM group_folders WHERE id=? AND group_id=?')->execute([$id, $groupId]);
                Audit::log('folder.delete', 'folder_id=' . $id . ' group_id=' . $groupId);
            } elseif ($action === 'add_camera_to_folder') {
                $folderId = (int)Util::post('folder_id', 0);
                $cameraId = (int)Util::post('camera_id', 0);
                $groupId = (int)Util::post('group_id', 0);
                if ($folderId > 0 && $cameraId > 0 && $groupId > 0) {
                    $pdo->prepare(DB::insertIgnoreSql('camera_folders', ['camera_id', 'folder_id']))
                        ->execute([$cameraId, $folderId]);
                    Audit::log('folder.add_camera', 'folder_id=' . $folderId . ' camera_id=' . $cameraId);
                }
                header('Location: /admin/groups?edit=' . $groupId . '&tab=2');
                exit;
            }
        }

        $edit = self::rowById('portal_groups', (int)($_GET['edit'] ?? 0));
        $delete = self::groupDeleteCandidate((int)($_GET['delete'] ?? 0));
        $editFolderId = (int)($_GET['edit_folder'] ?? 0);
        $allGroups = Repo::all('portal_groups', 'name ASC');
        $list = self::filteredRows('portal_groups', ['name', 'description'], 'name ASC');
        $groups = self::groupRowsWithDisplayLabels($list['rows'], $allGroups);
        $groupFolders = $edit ? Repo::foldersForGroup((int)$edit['id']) : [];
        $editFolder = null;
        if ($edit && $editFolderId > 0) {
            foreach ($groupFolders as $f) {
                if ((int)$f['id'] === $editFolderId) {
                    $editFolder = $f;
                    break;
                }
            }
        }
        // Данные для вкладок
        $groupCameras = [];
        $groupUsers = [];
        if ($edit) {
            foreach ($groupFolders as $f) {
                $groupCameras[(int)$f['id']] = Repo::camerasInFolder((int)$f['id']);
            }
            $groupUsers = Repo::usersForGroup((int)$edit['id']);
        }
        $activeTab = $edit ? max(1, min(3, (int)($_GET['tab'] ?? 1))) : 1;
        self::layout(self::t('groups.title', 'Группы'), function () use ($edit, $delete, $groups, $groupFolders, $groupCameras, $groupUsers, $editFolder, $message, $list, $activeTab) {
            self::notice($message);
            if ($delete) {
                self::groupDeletePanel($delete);
            }
            echo '<div class="group-admin-stack">';
            if ($edit) {
                // Режим редактирования: 3 вкладки
                echo '<div class="group-edit-tabs">';
                echo '<div class="group-edit-head"><a class="btn" href="/admin/groups">' . self::t('action.back', 'Назад') . '</a><h2>' . Util::h((string)$edit['name']) . '</h2></div>';
                self::groupEditTabNav($activeTab, count($groupFolders), count($groupUsers));

                // Вкладка 1: Настройки
                echo '<div class="tab-panel" data-tab-panel="1"' . ($activeTab !== 1 ? ' hidden' : '') . '>';
                echo '<form method="post" class="form">' . Csrf::field();
                echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
                echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
                echo '<label>' . self::t('column.description', 'Описание') . '<textarea name="description">' . Util::h($edit['description'] ?? '') . '</textarea></label>';
                echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('column.blocked', 'Заблокирована') . '</label>';
                echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form>';
                echo '</div>';

                // Вкладка 2: Папки и камеры
                echo '<div class="tab-panel" data-tab-panel="2"' . ($activeTab !== 2 ? ' hidden' : '') . '>';
                self::groupEditFoldersPanel($edit, $groupFolders, $groupCameras, $editFolder);
                echo '</div>';

                // Вкладка 3: Пользователи
                echo '<div class="tab-panel" data-tab-panel="3"' . ($activeTab !== 3 ? ' hidden' : '') . '>';
                self::groupEditUsersPanel($groupUsers, $groupFolders, (int)$edit['id']);
                echo '</div>';

                echo '</div>';
            } else {
                // Режим создания: форма без вкладок
                echo '<details class="panel group-create-panel">';
                echo '<summary><h2>' . self::t('groups.new', 'Новая группа') . '</h2></summary>';
                echo '<form method="post" class="form">' . Csrf::field();
                echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">';
                echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="" required></label>';
                echo '<label>' . self::t('column.description', 'Описание') . '<textarea name="description"></textarea></label>';
                echo '<label class="check"><input type="checkbox" name="blocked"> ' . self::t('column.blocked', 'Заблокирована') . '</label>';
                echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form>';
                echo '</details>';
            }

            if (!$edit) {
                self::table(self::t('groups.title', 'Группы'), ['id', 'name', 'blocked', 'description'], $groups, '/admin/groups', false, $list);
            }
            echo '</div>';
        });
    }

    private static function groupDeleteCandidate(int $id): ?array
    {
        return $id > 0 ? self::rowById('portal_groups', $id) : null;
    }

    private static function groupDeletePanel(array $group): void
    {
        $id = (int)$group['id'];
        $folderCount = self::countRowsByColumn('group_folders', 'group_id', $id);

        echo '<section class="panel delete-confirm" role="dialog" aria-labelledby="group-delete-title">';
        echo '<div class="section-head"><h2 id="group-delete-title">' . self::t('groups.deleteTitle', 'Удалить группу') . '</h2><a href="/admin/groups">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '<div class="alert warn">';
        echo '<strong>' . self::t('groups.deleteWarning', 'Это действие нельзя отменить.') . '</strong> ';
        echo self::t('groups.deleteWarningText', 'Удаление группы отвяжет её от пользователей и камер.');
        echo '</div>';
        echo '<dl class="delete-meta">';
        echo '<dt>' . self::t('column.id', 'ID') . '</dt><dd>' . $id . '</dd>';
        echo '<dt>' . self::t('column.name', 'Название') . '</dt><dd>' . Util::h($group['name']) . '</dd>';
        echo '<dt>' . self::t('folders.title', 'Папок') . '</dt><dd>' . $folderCount . '</dd>';
        echo '</dl>';
        echo '<form method="post" action="/admin/groups" class="form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">';
        echo '<label class="check"><input type="checkbox" name="confirm_delete" required> ' . self::t('groups.confirmDelete', 'Подтверждаю удаление группы из портала') . '</label>';
        echo '<div class="form-actions"><button class="danger">' . self::t('action.delete', 'Удалить') . '</button><a href="/admin/groups">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '</form></section>';
    }

    private static function countRowsByColumn(string $table, string $column, int $id): int
    {
        $stmt = DB::pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private static function servers(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $token = trim((string)Util::post('management_token'));
                if ($id > 0) {
                    $current = Repo::server($id);
                    $enc = $token !== '' ? Crypto::encrypt($token) : ($current['management_token_enc'] ?? null);
                    $pdo->prepare('UPDATE dvr_servers SET name=?, base_url=?, management_token_enc=?, blocked=? WHERE id=?')
                        ->execute([Util::post('name'), rtrim((string)Util::post('base_url'), '/'), $enc, Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([Util::post('name'), rtrim((string)Util::post('base_url'), '/'), Crypto::encrypt($token), Util::checkbox('blocked'), Util::now()]);
                }
                Audit::log('server.save', (string)Util::post('name'));
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM dvr_servers WHERE id=?')->execute([$id]);
                Audit::log('server.delete', 'server_id=' . $id);
            } elseif ($action === 'check' && $id > 0) {
                $server = Repo::server($id);
                $result = DvrClient::checkServer($id);
                $message = self::serverCheckNotice($result, $server['name'] ?? '');
            }
        }

        $edit = self::rowById('dvr_servers', (int)($_GET['edit'] ?? 0));
        $list = self::filteredRows('dvr_servers', ['name', 'base_url', 'last_check_result'], 'name ASC');
        $servers = $list['rows'];
        self::layout(self::t('servers.title', 'Серверы SesameDVR'), function () use ($edit, $servers, $message, $list) {
            self::notice($message);
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? self::t('servers.edit', 'Изменить сервер') : self::t('servers.new', 'Новый сервер')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>URL<input name="base_url" value="' . Util::h($edit['base_url'] ?? '') . '" placeholder="https://dvr.example.com" required></label>';
            echo '<label>' . self::t('servers.managementKey', 'Management key') . '<input name="management_token" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : '') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('servers.blocked', 'Заблокирован') . '</label>';
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('servers.title', 'Серверы'), ['name', 'base_url', 'blocked', 'last_check_result'], $servers, '/admin/servers', true, $list);
            echo '</div>';
        });
    }

    private static function agents(): void
    {
        Auth::requireAdmin();
        $servers = Repo::all('dvr_servers', 'name ASC');
        $selectedServerId = self::selectedServerId($servers);
        $selectedAgentId = trim((string)($_GET['agent_id'] ?? ''));
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $selectedServerId = (int)Util::post('server_id', $selectedServerId);
            $selectedAgentId = trim((string)Util::post('agent_id', $selectedAgentId));
            $result = null;

            if ($selectedServerId <= 0) {
                $message = self::t('agents.serverRequired', 'Выберите SesameDVR сервер');
            } elseif ($action === 'create') {
                $agentId = trim((string)Util::post('agent_id'));
                $name = trim((string)Util::post('name')) ?: $agentId;
                if ($agentId === '') {
                    $message = self::t('agents.agentId', 'Agent ID') . ' required';
                } else {
                    $payload = [
                        'id' => $agentId,
                        'name' => $name,
                        'enabled' => Util::checkbox('enabled') === 1,
                        'capabilities' => self::agentCapabilitiesFromText((string)Util::post('capabilities')),
                    ];
                    $password = trim((string)Util::post('password'));
                    if ($password !== '') {
                        $payload['password'] = $password;
                    }
                    $result = DvrClient::createAgent($selectedServerId, $payload);
                    $selectedAgentId = $agentId;
                }
            } elseif ($selectedAgentId === '') {
                $message = self::t('agents.agentId', 'Agent ID') . ' required';
            } elseif ($action === 'update') {
                $result = DvrClient::updateAgent($selectedServerId, $selectedAgentId, [
                    'name' => trim((string)Util::post('name')) ?: $selectedAgentId,
                    'enabled' => Util::checkbox('enabled') === 1,
                    'capabilities' => self::agentCapabilitiesFromText((string)Util::post('capabilities')),
                ]);
            } elseif ($action === 'delete') {
                $result = DvrClient::deleteAgent($selectedServerId, $selectedAgentId);
                if (!empty($result['ok'])) {
                    $selectedAgentId = '';
                }
            } elseif ($action === 'password') {
                $password = trim((string)Util::post('password'));
                $result = $password === ''
                    ? ['ok' => false, 'message' => self::t('agents.password', 'Enrollment password') . ' required']
                    : DvrClient::setAgentEnrollmentPassword($selectedServerId, $selectedAgentId, $password);
            } elseif ($action === 'revoke') {
                $result = DvrClient::revokeAgent($selectedServerId, $selectedAgentId);
            } elseif ($action === 'rotate') {
                $result = DvrClient::rotateAgentSecret($selectedServerId, $selectedAgentId);
            } elseif ($action === 'scan') {
                $result = DvrClient::scanAgentCameras($selectedServerId, $selectedAgentId);
            } elseif ($action === 'diagnostics') {
                $result = DvrClient::agentDiagnostics($selectedServerId, $selectedAgentId);
            } elseif ($action === 'command') {
                [$payload, $payloadError] = self::agentCommandPayload((string)Util::post('payload'), (string)Util::post('agent_camera_id'));
                if ($payloadError !== null) {
                    $result = ['ok' => false, 'message' => $payloadError];
                } else {
                    $timeout = (int)Util::post('timeout_ms', 0);
                    $result = DvrClient::agentCommand($selectedServerId, $selectedAgentId, trim((string)Util::post('command')) ?: 'test_camera', $payload, $timeout > 0 ? $timeout : null);
                }
            }

            if (is_array($result)) {
                $message = self::agentActionMessage($action, $result);
            }
        }

        $agentsResult = $selectedServerId > 0 ? DvrClient::listAgents($selectedServerId) : ['ok' => false, 'message' => self::t('agents.noServer', 'Сначала добавьте SesameDVR сервер с management token.'), 'data' => ['agents' => []]];
        $agents = is_array($agentsResult['data'] ?? null) && is_array(($agentsResult['data']['agents'] ?? null)) ? $agentsResult['data']['agents'] : [];
        if ($selectedAgentId === '' && $agents) {
            $selectedAgentId = (string)($agents[0]['id'] ?? '');
        }
        $selectedAgent = self::findAgentRow($agents, $selectedAgentId);
        $agentCamerasResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentCameras($selectedServerId, $selectedAgentId) : null;
        $agentCommandsResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentCommands($selectedServerId, $selectedAgentId) : null;
        $agentLogsResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentLogs($selectedServerId, $selectedAgentId) : null;

        self::layout(self::t('agents.title', 'Edge-агенты'), function () use ($servers, $selectedServerId, $selectedAgentId, $selectedAgent, $agentsResult, $agents, $agentCamerasResult, $agentCommandsResult, $agentLogsResult, $message) {
            self::notice($message);
            if (!$servers) {
                self::notice(self::t('agents.noServer', 'Сначала добавьте SesameDVR сервер с management token.'));
                return;
            }

            echo '<section class="panel agents-toolbar"><form method="get" action="/admin/agents" class="filters">';
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id" onchange="this.form.submit()">';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . ($selectedServerId === (int)$server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label><button>' . self::t('action.update', 'Обновить') . '</button></form></section>';

            if ($selectedServerId <= 0) {
                return;
            }

            echo '<div class="admin-grid agents-admin-grid"><details class="panel agent-create-panel"><summary><span><strong>' . self::t('agents.new', 'Новый агент') . '</strong><small>' . self::t('agents.createHint', 'Создание агента нужно только перед первичной установкой edge-устройства.') . '</small></span></summary>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="create"><input type="hidden" name="server_id" value="' . (int)$selectedServerId . '">';
            echo '<label>' . self::t('agents.agentId', 'Agent ID') . '<input name="agent_id" placeholder="agt-office-1" required></label>';
            echo '<label>' . self::t('agents.agentName', 'Название агента') . '<input name="name" placeholder="Office NanoPi"></label>';
            echo '<label>' . self::t('agents.password', 'Пароль enrollment') . '<input name="password" autocomplete="new-password"></label>';
            echo '<label>' . self::t('agents.capabilities', 'Возможности') . '<input name="capabilities" value="rtmp_push,onvif_events"></label>';
            echo '<label class="check"><input type="checkbox" name="enabled" checked> ' . self::t('agents.enabled', 'Включён') . '</label>';
            echo '<button class="primary">' . self::t('agents.create', 'Создать агента') . '</button></form></details>';

            $agentsSummary = !empty($agentsResult['ok'])
                ? self::t('agents.loaded', 'Загружено агентов') . ': ' . count($agents)
                : self::agentResultSummary($agentsResult, self::t('agents.noAgents', 'Агенты не найдены'));
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('agents.title', 'Edge-агенты') . '</h2><span class="muted">' . Util::h($agentsSummary) . '</span></div>';
            if (!empty($agentsResult['message'])) {
                self::technicalResult((string)$agentsResult['message'], self::t('agents.details', 'Технические детали'));
            }
            if (!$agents) {
                echo '<p class="muted">' . self::t('agents.noAgents', 'Агенты не найдены') . '</p>';
            }
            echo '<div class="agent-list">';
            foreach ($agents as $agent) {
                self::agentCard($selectedServerId, $agent, $selectedAgentId);
            }
            echo '</div></section></div>';

            if ($selectedAgentId !== '') {
                self::agentDetails($selectedServerId, $selectedAgentId, $selectedAgent, $agentCamerasResult, $agentCommandsResult, $agentLogsResult);
            }
        });
    }

    private static function agentSnapshotProxy(): void
    {
        Auth::requireAdmin();
        $serverId = (int)($_GET['server_id'] ?? 0);
        $agentId = trim((string)($_GET['agent_id'] ?? ''));
        $cameraId = trim((string)($_GET['camera_id'] ?? ''));
        if ($serverId <= 0 || $agentId === '' || $cameraId === '') {
            http_response_code(400);
            echo 'missing snapshot parameters';
            return;
        }

        $result = DvrClient::agentSnapshot($serverId, $agentId, $cameraId, !empty($_GET['fresh']));
        if (empty($result['ok'])) {
            http_response_code((int)($result['status'] ?? 502) ?: 502);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($result['message'] ?? 'snapshot failed');
            return;
        }

        $contentType = (string)($result['contentType'] ?? 'image/jpeg');
        if (!str_starts_with(strtolower($contentType), 'image/')) {
            $contentType = 'image/jpeg';
        }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store');
        echo (string)($result['data'] ?? '');
    }

    private static function selectedServerId(array $servers): int
    {
        $requested = (int)($_GET['server_id'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        foreach ($servers as $server) {
            if ((int)($server['blocked'] ?? 0) === 0) {
                return (int)$server['id'];
            }
        }
        return $servers ? (int)$servers[0]['id'] : 0;
    }

    private static function agentCapabilitiesFromText(string $text): array
    {
        $parts = preg_split('/[\s,]+/', trim($text)) ?: [];
        $capabilities = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $capabilities[$part] = true;
            }
        }
        return array_keys($capabilities);
    }

    private static function agentCommandPayload(string $text, string $agentCameraId): array
    {
        $text = trim($text);
        $payload = [];
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                return [[], 'Payload JSON must be an object'];
            }
            $payload = $decoded;
        }

        $agentCameraId = trim($agentCameraId);
        if ($agentCameraId !== '') {
            $payload += [
                'agentCameraId' => $agentCameraId,
                'cameraId' => $agentCameraId,
            ];
        }
        return [$payload, null];
    }

    private static function agentActionMessage(string $action, array $result): string
    {
        $data = $result['data'] ?? null;
        if (!empty($result['ok']) && is_array($data) && !empty($data['agentSecret'])) {
            return self::t('agents.newSecret', 'Новый секрет агента') . ': ' . $data['agentSecret'];
        }

        if (empty($result['ok'])) {
            return self::agentResultSummary($result, self::t('agents.actionFailed', 'Операция не выполнена'));
        }

        return match ($action) {
            'scan', 'diagnostics', 'command' => self::t('agents.actionQueued', 'Команда поставлена в очередь'),
            default => self::t('agents.actionCompleted', 'Операция выполнена'),
        };
    }

    private static function agentResultSummary(?array $result, string $okText): string
    {
        if (!$result) {
            return '';
        }
        if (!empty($result['ok'])) {
            return $okText;
        }

        $status = (int)($result['status'] ?? 0);
        $prefix = self::t('agents.actionFailed', 'Операция не выполнена');
        if ($status > 0) {
            return $prefix . ' · HTTP ' . $status;
        }

        $message = trim((string)($result['message'] ?? ''));
        return $message !== '' && !str_starts_with($message, 'HTTP ')
            ? $prefix . ' · ' . $message
            : $prefix;
    }

    private static function findAgentRow(array $agents, string $agentId): ?array
    {
        foreach ($agents as $agent) {
            if (is_array($agent) && (string)($agent['id'] ?? '') === $agentId) {
                return $agent;
            }
        }
        return null;
    }

    private static function agentCard(int $serverId, array $agent, string $selectedAgentId): void
    {
        $id = (string)($agent['id'] ?? '');
        if ($id === '') {
            return;
        }
        $name = (string)($agent['name'] ?? $id);
        $status = (string)($agent['status'] ?? 'offline');
        $capabilities = is_array($agent['capabilities'] ?? null) ? implode(',', $agent['capabilities']) : '';
        $active = $id === $selectedAgentId ? ' active' : '';
        $href = '/admin/agents?' . http_build_query(['server_id' => $serverId, 'agent_id' => $id]);

        echo '<article class="agent-card' . $active . '">';
        echo '<div class="agent-card-head"><div><a class="agent-card-title" href="' . Util::h($href) . '">' . Util::h($name) . '</a><code>' . Util::h($id) . '</code></div>';
        echo self::statusPill($status) . '</div>';
        echo '<dl class="agent-meta">';
        echo '<dt>' . self::t('agents.version', 'Версия') . '</dt><dd>' . Util::h($agent['version'] ?? '-') . '</dd>';
        echo '<dt>' . self::t('agents.lastSeen', 'Последняя связь') . '</dt><dd>' . self::localTime($agent['lastSeenAt'] ?? '') . '</dd>';
        echo '<dt>' . self::t('agents.cameraCount', 'Камеры') . '</dt><dd>' . Util::h($agent['cameraCount'] ?? 0) . '</dd>';
        echo '<dt>' . self::t('agents.mediaSessions', 'Медиа-сессии') . '</dt><dd>' . Util::h($agent['activeMediaSessions'] ?? 0) . '</dd>';
        echo '</dl>';
        echo '<details class="agent-settings-details" open><summary>' . self::t('agents.settings', 'Настройки') . '</summary>';
        echo '<form method="post" class="agent-edit-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="update"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($id) . '">';
        echo '<label>' . self::t('agents.agentName', 'Название агента') . '<input name="name" value="' . Util::h($name) . '"></label>';
        echo '<label>' . self::t('agents.capabilities', 'Возможности') . '<input name="capabilities" value="' . Util::h($capabilities) . '"></label>';
        echo '<label class="check"><input type="checkbox" name="enabled" ' . (!empty($agent['enabled']) ? 'checked' : '') . '> ' . self::t('agents.enabled', 'Включён') . '</label>';
        echo '<button>' . self::t('action.save', 'Сохранить') . '</button></form>';
        echo '</details>';
        echo '<div class="agent-action-block"><strong>' . self::t('agents.actions', 'Действия') . '</strong>';
        echo '<div class="row-actions row-actions-icons agent-actions">';
        self::smallPost('/admin/agents', ['action' => 'scan', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.scan', 'Сканировать ONVIF'), '', '', 'scan');
        self::smallPost('/admin/agents', ['action' => 'diagnostics', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.diagnostics', 'Диагностика'), '', '', 'diagnostics');
        self::smallPost('/admin/agents', ['action' => 'revoke', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.revoke', 'Отозвать секрет'), '', '', 'ban');
        self::smallPost('/admin/agents', ['action' => 'rotate', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.rotateSecret', 'Сменить секрет'), '', '', 'key');
        self::smallPost('/admin/agents', ['action' => 'delete', 'server_id' => $serverId, 'agent_id' => $id], self::t('action.delete', 'Удалить'), 'danger', '', 'trash');
        echo '</div></div>';
        echo '<details class="agent-settings-details"><summary>' . self::t('agents.enrollment', 'Enrollment') . '</summary>';
        echo '<form method="post" class="agent-password-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="password"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($id) . '">';
        echo '<label>' . self::t('agents.password', 'Пароль enrollment') . '<input name="password" autocomplete="new-password"></label><button>' . self::t('agents.setPassword', 'Задать пароль') . '</button></form>';
        echo '</details>';
        echo '</article>';
    }

    private static function agentDetails(int $serverId, string $agentId, ?array $agent, ?array $camerasResult, ?array $commandsResult, ?array $logsResult): void
    {
        echo '<details class="panel agent-command-panel"><summary><span><strong>' . self::t('agents.commandConsole', 'Консоль команд') . '</strong><small>' . Util::h($agent['name'] ?? $agentId) . ' · ' . Util::h($agentId) . '</small></span></summary>';
        echo '<form method="post" class="form agent-command-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="command"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($agentId) . '">';
        echo '<div class="form-row"><label>' . self::t('agents.command', 'Команда') . '<input name="command" value="test_camera"></label>';
        echo '<label>' . self::t('cameras.agentCameraId', 'Agent camera ID') . '<input name="agent_camera_id"></label></div>';
        echo '<label>' . self::t('agents.payload', 'Payload JSON') . '<textarea name="payload" placeholder="{&quot;agentCameraId&quot;:&quot;cam1&quot;}"></textarea></label>';
        echo '<label>' . self::t('agents.timeout', 'Таймаут, мс') . '<input name="timeout_ms" type="number" min="1000" step="1000" placeholder="30000"></label>';
        echo '<button class="primary">' . self::t('agents.sendCommand', 'Отправить команду') . '</button></form></details>';

        $cameras = is_array($camerasResult['data'] ?? null) && is_array(($camerasResult['data']['cameras'] ?? null)) ? $camerasResult['data']['cameras'] : [];
        $cameraSummary = !empty($camerasResult['ok'])
            ? count($cameras)
            : self::agentResultSummary($camerasResult, '0');
        echo '<section class="panel"><div class="section-head"><h2>' . self::t('agents.cameras', 'Камеры агента') . '</h2><span class="muted">' . Util::h((string)$cameraSummary) . '</span></div>';
        if (!empty($camerasResult['message'])) {
            self::technicalResult((string)$camerasResult['message'], self::t('agents.details', 'Технические детали'));
        }
        if (!$cameras) {
            echo '<p class="muted">-</p>';
        }
        echo '<div class="agent-camera-grid">';
        foreach ($cameras as $camera) {
            if (is_array($camera)) {
                self::agentCameraCard($serverId, $agentId, $camera);
            }
        }
        echo '</div></section>';

        echo '<div class="grid cols-2">';
        self::jsonDetailsPanel(self::t('agents.lastCommands', 'Последние команды'), $commandsResult['data'] ?? $commandsResult);
        self::jsonDetailsPanel(self::t('agents.lastLogs', 'Последние записи журнала'), $logsResult['data'] ?? $logsResult);
        echo '</div>';
    }

    private static function agentCameraCard(int $serverId, string $agentId, array $camera): void
    {
        $cameraId = (string)($camera['agentCameraId'] ?? $camera['id'] ?? '');
        if ($cameraId === '') {
            return;
        }
        $name = (string)($camera['name'] ?? $cameraId);
        $stream = Util::dvrStreamSlug($name);
        $snapshotUrl = '/admin/agents/snapshot?' . http_build_query(['server_id' => $serverId, 'agent_id' => $agentId, 'camera_id' => $cameraId]);
        $createUrl = '/admin/cameras?' . http_build_query([
            'mode' => 'edge_agent',
            'server_id' => $serverId,
            'agent_id' => $agentId,
            'agent_camera_id' => $cameraId,
            'name' => $name,
            'stream' => $stream,
            'onvif_events_requested' => !empty($camera['onvifStatus']) ? 1 : 0,
        ]);

        echo '<article class="agent-camera-card">';
        echo '<div class="agent-snapshot"><img src="' . Util::h($snapshotUrl) . '" alt=""></div>';
        echo '<div><strong>' . Util::h($name) . '</strong><code>' . Util::h($cameraId) . '</code></div>';
        echo '<dl class="agent-meta">';
        echo '<dt>' . self::t('agents.source', 'Источник') . '</dt><dd>' . Util::h($camera['sourceKind'] ?? '-') . '</dd>';
        echo '<dt>RTSP</dt><dd>' . Util::h($camera['rtspUrlRedacted'] ?? '-') . '</dd>';
        echo '<dt>' . self::t('agents.media', 'Медиа') . '</dt><dd>' . Util::h(self::agentValueSummary($camera['mediaStatus'] ?? null)) . '</dd>';
        echo '<dt>' . self::t('agents.onvif', 'ONVIF') . '</dt><dd>' . Util::h(self::agentValueSummary($camera['onvifStatus'] ?? null)) . '</dd>';
        echo '<dt>' . self::t('agents.lastSeen', 'Последняя связь') . '</dt><dd>' . self::localTime($camera['lastSeenAt'] ?? '') . '</dd>';
        echo '</dl>';
        echo '<a class="btn" href="' . Util::h($createUrl) . '">' . self::t('agents.useCamera', 'Создать камеру в Portal') . '</a>';
        echo '</article>';
    }

    private static function agentValueSummary(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (!is_array($value)) {
            return self::t('agents.unknown', 'неизвестно');
        }

        $parts = [];
        foreach (['status', 'state', 'backend'] as $key) {
            if (!empty($value[$key]) && is_scalar($value[$key])) {
                $parts[] = (string)$value[$key];
            }
        }
        if (array_key_exists('running', $value)) {
            $parts[] = self::truthyMetricValue($value['running']) ? self::t('agents.running', 'работает') : self::t('agents.stopped', 'остановлен');
        }
        if (array_key_exists('online', $value)) {
            $parts[] = self::truthyMetricValue($value['online']) ? self::t('agents.online', 'online') : self::t('agents.offline', 'offline');
        }
        $error = $value['lastError'] ?? $value['error'] ?? null;
        if (is_scalar($error) && trim((string)$error) !== '') {
            $parts[] = 'error: ' . mb_substr(trim((string)$error), 0, 120);
        }

        $parts = array_values(array_unique(array_filter($parts, static fn($part) => $part !== '')));
        return $parts ? implode(' · ', $parts) : self::t('agents.technicalData', 'Технические данные');
    }

    private static function jsonDetailsPanel(string $title, mixed $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo '<details class="panel json-details"><summary><span><strong>' . Util::h($title) . '</strong><small>' . self::t('agents.details', 'Технические детали') . '</small></span></summary><pre class="json-panel">' . Util::h($json === false ? '' : $json) . '</pre></details>';
    }

    private static function statusPill(string $status): string
    {
        $class = match ($status) {
            'online' => 'success',
            'disabled' => 'warn',
            'offline' => 'danger',
            default => 'info',
        };
        return '<span class="pill ' . $class . '">' . Util::h($status) . '</span>';
    }

    private static function cameras(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $selection = Util::post('server_selection') === 'auto' ? 'auto' : 'manual';
                $controlMode = self::cameraControlMode(Util::post('dvr_control_mode'));
                $serverId = (int)Util::post('server_id', 0) ?: null;
                if ($controlMode === 'edge_agent') {
                    $selection = 'manual';
                } elseif (!$serverId) {
                    $selection = 'auto';
                }
                if ($selection === 'auto' && !$serverId) {
                    $serverId = self::randomActiveServerId();
                }
                $sourceUrl = trim((string)Util::post('source_url'));
                [$name, $stream] = self::cameraNamesFromInput(
                    [
                        'display_name' => Util::post('display_name', Util::post('name')),
                        'dvr_stream_name' => Util::post('dvr_stream_name'),
                    ],
                    $id > 0 ? Repo::camera($id) : null
                );
                $agentId = trim((string)Util::post('agent_id'));
                $agentCameraId = trim((string)Util::post('agent_camera_id'));
                if ($name === '' || $stream === '') {
                    $message = I18n::t('cameras.nameOrStreamRequired', 'Stream title or technical stream name is required');
                } elseif (!Util::isDvrStreamName($stream)) {
                    $message = I18n::t('cameras.invalidStreamName', 'Technical stream name must start with a Latin letter or digit and can contain only Latin letters, digits, dot, hyphen, and underscore, up to 128 characters.');
                } elseif ($controlMode === 'managed' && $sourceUrl === '') {
                    $message = I18n::t('cameras.sourceRequired', 'Source URL is required for full DVR management mode');
                } elseif ($controlMode === 'edge_agent' && (!$serverId || $agentId === '' || $agentCameraId === '')) {
                    $message = I18n::t('cameras.agentRequired', 'Edge-agent mode requires server, Agent ID, and Agent camera ID');
                } else {
                    $values = [
                        $name,
                        $sourceUrl,
                        $serverId,
                        $selection,
                        self::nullableFloat(Util::post('latitude')),
                        self::nullableFloat(Util::post('longitude')),
                        (int)Util::post('direction_deg', 0),
                        (int)Util::post('view_angle_deg', 60),
                        Util::post('retention_days', '7d'),
                        Util::checkbox('archive_enabled'),
                        Util::checkbox('webrtc_fast_start'),
                        Util::checkbox('event_archive_retention_enabled'),
                        self::cameraEventArchiveMaxBytesFromPost(),
                        self::cameraOptionalString(Util::post('event_archive_max_duration')),
                        self::cameraOptionalString(Util::post('event_archive_max_age')),
                        Util::checkbox('timelapse_enabled'),
                        self::cameraPositiveInt(Util::post('timelapse_frames_per_hour', 60), 60),
                        self::cameraOptionalString(Util::post('timelapse_retention_days')),
                        self::cameraPositiveInt(Util::post('timelapse_playback_fps', 25), 25),
                        self::cameraTimelineRepairMode(Util::post('direct_archive_video_timeline_repair_mode')),
                        self::cameraAudioCodec(Util::post('audio_codec', 'copy')),
                        $controlMode,
                        $agentId !== '' ? $agentId : null,
                        $agentCameraId !== '' ? $agentCameraId : null,
                        Util::checkbox('onvif_events_requested'),
                        trim((string)Util::post('onvif_host')),
                        max(1, min(65535, (int)Util::post('onvif_port', 80))),
                        trim((string)Util::post('onvif_username')),
                        trim((string)Util::post('onvif_password')),
                        Util::checkbox('watermark_enabled'),
                        self::watermarkIntensity(Util::post('watermark_intensity', 16)),
                        Util::checkbox('blocked'),
                        $stream,
                    ];
                    if ($id > 0) {
                        $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, archive_enabled=?, webrtc_fast_start=?, event_archive_retention_enabled=?, event_archive_max_bytes=?, event_archive_max_duration=?, event_archive_max_age=?, timelapse_enabled=?, timelapse_frames_per_hour=?, timelapse_retention_days=?, timelapse_playback_fps=?, direct_archive_video_timeline_repair_mode=?, audio_codec=?, dvr_control_mode=?, agent_id=?, agent_camera_id=?, onvif_events_requested=?, onvif_host=?, onvif_port=?, onvif_username=?, onvif_password=?, watermark_enabled=?, watermark_intensity=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                            ->execute([...$values, Util::now(), $id]);
                    } else {
                        $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, archive_enabled, webrtc_fast_start, event_archive_retention_enabled, event_archive_max_bytes, event_archive_max_duration, event_archive_max_age, timelapse_enabled, timelapse_frames_per_hour, timelapse_retention_days, timelapse_playback_fps, direct_archive_video_timeline_repair_mode, audio_codec, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, onvif_host, onvif_port, onvif_username, onvif_password, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([...$values, Util::now(), Util::now()]);
                        $id = DB::lastInsertId('cameras');
                    }
                    self::replaceLinks('camera_folders', 'camera_id', $id, 'folder_id', $_POST['folder_ids'] ?? []);
                    $sync = DvrClient::syncCamera($id);
                    $message = self::cameraSaveNotice($sync);
                    Audit::log('camera.save', $name . ' mode=' . $controlMode . ' sync=' . $sync['message']);
                }
            } elseif ($action === 'delete' && $id > 0) {
                $camera = Repo::camera($id);
                if (!$camera) {
                    $message = self::t('cameras.deleteMissing', 'Камера уже удалена или не найдена');
                } elseif (Util::checkbox('confirm_delete') !== 1) {
                    $message = self::t('cameras.deleteConfirmRequired', 'Подтвердите удаление камеры');
                } else {
                    $deleteDvrStream = Util::checkbox('delete_dvr_stream') === 1;
                    $dvrMessage = '';
                    if ($deleteDvrStream) {
                        $deleteResult = DvrClient::deleteCameraStream($id, true);
                        $dvrMessage = $deleteResult['message'];
                        if (!$deleteResult['ok']) {
                            $message = self::t('cameras.deleteDvrFailed', 'Поток на DVR не удалён') . ': ' . $dvrMessage;
                            Audit::log('camera.delete_failed', 'camera_id=' . $id . ' dvr=yes result=' . $dvrMessage);
                        }
                    }

                    if ($message === '') {
                        $pdo->prepare('DELETE FROM camera_folders WHERE camera_id=?')->execute([$id]);
                        $pdo->prepare('DELETE FROM cameras WHERE id=?')->execute([$id]);
                        $message = self::t('cameras.deleteDone', 'Камера удалена');
                        if ($dvrMessage !== '') {
                            $message .= ': ' . $dvrMessage;
                        }
                        Audit::log('camera.delete', 'camera_id=' . $id . ' dvr=' . ($deleteDvrStream ? 'yes' : 'no') . ' result=' . $dvrMessage);
                    }
                }
            } elseif ($action === 'sync' && $id > 0) {
                $result = DvrClient::syncCamera($id);
                $message = self::cameraSyncNotice($result);
            } elseif ($action === 'issue_camera_token' && $id > 0) {
                TokenService::issueCameraToken($id);
            } elseif ($action === 'revoke_camera_token' && $id > 0) {
                TokenService::revokeCameraToken($id);
            }
        }

        $edit = self::rowById('cameras', (int)($_GET['edit'] ?? 0));
        $form = self::cameraFormDefaults($edit);
        $delete = self::cameraDeleteCandidate((int)($_GET['delete'] ?? 0));
        $linkedFolders = $edit ? self::linkedIds('camera_folders', 'camera_id', (int)$edit['id'], 'folder_id') : [];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $folders = Repo::allFolders();
        $list = self::filteredCameras();
        $backPath = self::safeLocalPath((string)($_GET['back'] ?? ''));
        $cameras = $list['rows'];
        self::layout(self::t('cameras.title', 'Камеры'), function () use ($edit, $form, $delete, $servers, $folders, $linkedFolders, $cameras, $message, $list, $backPath) {
            self::notice($message);
            if ($delete) {
                self::cameraDeletePanel($delete);
            }
            echo '<div class="camera-admin-stack">';
            echo '<details class="panel camera-create-panel"' . ($edit || !empty($_GET['new']) ? ' open' : '') . '>';
            echo '<summary><h2>' . ($edit ? self::t('cameras.edit', 'Изменить камеру') : self::t('cameras.new', 'Новая камера')) . '</h2>';
            echo '<span class="camera-create-actions">';
            if ($backPath !== '') {
                echo '<a class="btn" href="' . Util::h($backPath) . '">' . self::t('action.back', 'Назад') . '</a>';
            }
            if ($edit) {
                echo '<a class="btn" href="' . Util::h(self::tableActionUrl('/admin/cameras', [], $list)) . '">' . self::t('cameras.new', 'Новая камера') . '</a>';
            }
            echo '<a class="btn" href="/admin/cameras/import">' . self::t('cameras.importFromDvr', 'Импорт с DVR') . '</a>';
            echo '</span></summary>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('cameras.displayName', 'Название потока') . '<input name="display_name" value="' . Util::h($form['name'] ?? '') . '"></label>';
            $edgeAgentMode = ($form['dvr_control_mode'] ?? 'managed') === 'edge_agent';
            echo '<label>' . self::t('cameras.mode', 'Режим камеры') . '<select name="dvr_control_mode" data-camera-mode-select>';
            echo '<option value="managed" ' . (($form['dvr_control_mode'] ?? 'managed') === 'managed' ? 'selected' : '') . '>' . self::t('cameras.modeManaged', 'Полное управление на DVR') . '</option>';
            echo '<option value="edge_agent" ' . (($form['dvr_control_mode'] ?? '') === 'edge_agent' ? 'selected' : '') . '>' . self::t('cameras.modeEdgeAgent', 'Edge Agent push stream') . '</option>';
            echo '<option value="read_only" ' . (($form['dvr_control_mode'] ?? '') === 'read_only' ? 'selected' : '') . '>' . self::t('cameras.modeReadOnly', 'Read-only поток с DVR') . '</option></select></label>';
            echo '<label>' . self::t('cameras.sourceUrl', 'URL источника') . '<input name="source_url" value="' . Util::h($form['source_url'] ?? '') . '"></label>';
            echo '<div data-camera-onvif-field' . ($edgeAgentMode ? ' hidden' : '') . '>';
            echo '<details class="panel camera-onvif-panel"' . (trim((string)($form['onvif_host'] ?? '')) !== '' ? ' open' : '') . '><summary><h3>ONVIF</h3></summary>';
            echo '<div class="form-grid cols-2">';
            echo '<label>' . self::t('cameras.onvifHost', 'IP-адрес / Хост') . '<input name="onvif_host" value="' . Util::h($form['onvif_host'] ?? '') . '" placeholder="10.0.0.10"></label>';
            echo '<label>' . self::t('cameras.onvifPort', 'Порт') . '<input name="onvif_port" type="number" min="1" max="65535" value="' . Util::h((string)($form['onvif_port'] ?? 80)) . '"></label>';
            echo '<label>' . self::t('cameras.onvifUsername', 'Логин') . '<input name="onvif_username" value="' . Util::h($form['onvif_username'] ?? '') . '" placeholder="admin"></label>';
            echo '<label>' . self::t('cameras.onvifPassword', 'Пароль') . '<input name="onvif_password" type="password" value="' . Util::h($form['onvif_password'] ?? '') . '" placeholder="••••••"></label>';
            echo '</div></details></div>';
            $isAdmin = (Auth::user()['role'] ?? '') === 'admin';
            if ($isAdmin && $edit) {
                $hasToken = trim((string)($edit['permanent_token_hash'] ?? '')) !== '';
                echo '<div class="camera-permanent-token-row">';
                echo '<div class="static-token-head">';
                echo '<span class="static-token-label">' . Util::h(self::t('cameras.permanentToken', 'Постоянный токен камеры')) . '</span>';
                echo '<span class="pill ' . ($hasToken ? 'success' : 'danger') . '">' . Util::h($hasToken ? self::t('token.staticPresent', 'есть') : self::t('token.staticMissing', 'нет')) . '</span>';
                echo '</div>';
                if ($hasToken) {
                    $encoded = trim((string)($edit['permanent_token_enc'] ?? ''));
                    $plain = $encoded !== '' ? Crypto::decrypt($encoded) : '';
                    echo '<span class="static-token-field">';
                    echo '<input class="static-token-input" type="text" readonly value="' . Util::h($plain) . '">';
                    echo '</span>';
                }
                echo '<span class="static-token-actions">';
                self::smallPostButton(
                    'camera-token-issue-form',
                    $hasToken ? self::t('token.cameraTokenReplace', 'Заменить токен') : self::t('token.cameraTokenIssue', 'Выдать постоянный токен'),
                    '',
                    $hasToken ? 'token-refresh' : 'token-issue'
                );
                if ($hasToken) {
                    self::smallPostButton('camera-token-revoke-form', self::t('action.revoke', 'Отозвать'), 'danger', 'token-revoke');
                }
                echo '</span></div>';
            }
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id"><option value="">' . self::t('cameras.serverAutoNone', 'Авто/не выбран') . '</option>';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . (($form['server_id'] ?? '') == $server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>' . self::t('cameras.serverSelection', 'Выбор сервера') . '<select name="server_selection"><option value="manual">' . self::t('cameras.selectionManual', 'конкретный') . '</option><option value="auto" ' . (($form['server_selection'] ?? '') === 'auto' ? 'selected' : '') . '>' . self::t('cameras.selectionAuto', 'автоматический случайный') . '</option></select></label>';
            $streamNameHint = self::t('cameras.streamNameHint', 'Starts with A-Z, a-z, or 0-9; then A-Z, a-z, 0-9, dot, hyphen, and underscore are allowed. Leave empty to generate it.');
            echo '<label>' . self::t('cameras.streamName', 'Техническое имя потока') . '<input name="dvr_stream_name" value="' . Util::h($form['dvr_stream_name'] ?? '') . '" maxlength="' . Util::DVR_STREAM_NAME_MAX_BYTES . '" pattern="' . Util::DVR_STREAM_NAME_HTML_PATTERN . '" placeholder="domofon-g-sukhum-ul-kiaraz-9-p1" autocomplete="off" autocapitalize="none" spellcheck="false" title="' . Util::h($streamNameHint) . '"></label>';
            echo '<div class="form-row" data-camera-agent-field' . ($edgeAgentMode ? '' : ' hidden') . '><label>' . self::t('cameras.agentId', 'Agent ID') . '<input name="agent_id" value="' . Util::h($form['agent_id'] ?? '') . '"></label><label>' . self::t('cameras.agentCameraId', 'Agent camera ID') . '<input name="agent_camera_id" value="' . Util::h($form['agent_camera_id'] ?? '') . '"></label></div>';
            echo '<label class="check" data-camera-agent-field' . ($edgeAgentMode ? '' : ' hidden') . '><input type="checkbox" name="onvif_events_requested" ' . (!empty($form['onvif_events_requested']) ? 'checked' : '') . '> ' . self::t('cameras.onvifEvents', 'Запускать ONVIF events через агента') . '</label>';
            $watermarkEnabled = !empty($form['watermark_enabled']);
            echo '<div class="form-row"><label class="check"><input type="checkbox" name="watermark_enabled" data-watermark-toggle ' . ($watermarkEnabled ? 'checked' : '') . '> ' . self::t('cameras.watermarkEnabled', 'Показывать водяной знак с логином в плеере') . '</label>';
            echo '<label data-watermark-dependent' . ($watermarkEnabled ? '' : ' hidden') . '>' . self::t('cameras.watermarkIntensity', 'Интенсивность водяного знака, %') . '<input name="watermark_intensity" type="number" min="1" max="100" value="' . Util::h(self::watermarkIntensity($form['watermark_intensity'] ?? 16)) . '"></label></div>';
            $lat = $form['latitude'] ?? '';
            $lng = $form['longitude'] ?? '';
            echo '<details class="camera-location-options" data-camera-location-options><summary>' . self::t('cameras.locationOptions', 'Расположение камеры') . '</summary>';
            echo '<div class="form-row"><label>' . self::t('geo.latitude', 'Широта') . '<input id="camera-latitude" name="latitude" value="' . Util::h($lat) . '"></label><label>' . self::t('geo.longitude', 'Долгота') . '<input id="camera-longitude" name="longitude" value="' . Util::h($lng) . '"></label></div>';
            echo '<div class="camera-position-field"><div class="camera-position-head"><strong>' . self::t('cameras.position', 'Положение на карте') . '</strong><button type="button" class="camera-map-clear">' . self::t('cameras.clearPosition', 'Очистить точку') . '</button></div>';
            echo '<div id="camera-position-map" class="camera-position-map" data-lat="' . Util::h($lat) . '" data-lng="' . Util::h($lng) . '"></div></div>';
            echo '<div class="form-row"><label>' . self::t('cameras.direction', 'Направление') . '<input id="camera-direction" name="direction_deg" type="number" min="0" max="359" value="' . Util::h($form['direction_deg'] ?? 0) . '"></label><label>' . self::t('cameras.viewAngle', 'Угол обзора') . '<input name="view_angle_deg" type="number" min="1" max="180" value="' . Util::h($form['view_angle_deg'] ?? 60) . '"></label></div>';
            echo '</details>';
            $timelineRepairMode = self::cameraTimelineRepairMode($form['direct_archive_video_timeline_repair_mode'] ?? null) ?? '';
            $audioCodec = self::cameraAudioCodec($form['audio_codec'] ?? 'copy');
            $archiveEnabled = !empty($form['archive_enabled']);
            $eventArchiveEnabled = !empty($form['event_archive_retention_enabled']);
            $timelapseEnabled = !empty($form['timelapse_enabled']);
            echo '<details class="camera-stream-options" data-dvr-stream-options><summary>' . self::t('cameras.dvrStreamOptions', 'Настройки потока DVR') . '</summary>';
            echo '<div class="form-row camera-dvr-toggle-row"><label class="check"><input type="checkbox" name="archive_enabled" data-dvr-toggle="archive" ' . ($archiveEnabled ? 'checked' : '') . '> ' . self::t('cameras.archiveEnabled', 'Пишет архив') . '</label>';
            echo '<label data-dvr-dependent="archive"' . ($archiveEnabled ? '' : ' hidden') . '>' . self::t('cameras.retention', 'Глубина архива') . '<input name="retention_days" value="' . Util::h($form['retention_days'] ?? '7d') . '"></label></div>';
            echo '<label class="check"><input type="checkbox" name="webrtc_fast_start" ' . (!empty($form['webrtc_fast_start']) ? 'checked' : '') . '> ' . self::t('cameras.webrtcFastStart', 'WebRTC FastStart') . '</label>';
            echo '<label class="check"><input type="checkbox" name="event_archive_retention_enabled" data-dvr-toggle="event-archive" ' . ($eventArchiveEnabled ? 'checked' : '') . '> ' . self::t('cameras.eventArchiveRetentionEnabled', 'Сохранять архив по событиям') . '</label>';
            echo '<div class="camera-dvr-dependent" data-dvr-dependent="event-archive"' . ($eventArchiveEnabled ? '' : ' hidden') . '>';
            echo '<div class="form-row"><label>' . self::t('cameras.eventArchiveMaxMb', 'Лимит размера архива событий, MB') . '<input name="event_archive_max_mb" type="number" min="0" step="0.01" value="' . Util::h(self::eventArchiveBytesToMegabytesInput($form['event_archive_max_bytes'] ?? null)) . '"></label><label>' . self::t('cameras.eventArchiveMaxDuration', 'Максимальная длительность архива событий') . '<input name="event_archive_max_duration" value="' . Util::h($form['event_archive_max_duration'] ?? '') . '" placeholder="6h"></label></div>';
            echo '<label>' . self::t('cameras.eventArchiveMaxAge', 'Срок хранения архива событий') . '<input name="event_archive_max_age" value="' . Util::h($form['event_archive_max_age'] ?? '') . '" placeholder="30d"></label>';
            echo '</div>';
            echo '<label class="check"><input type="checkbox" name="timelapse_enabled" data-dvr-toggle="timelapse" ' . ($timelapseEnabled ? 'checked' : '') . '> ' . self::t('cameras.timelapseEnabled', 'Писать timelapse') . '</label>';
            echo '<div class="camera-dvr-dependent" data-dvr-dependent="timelapse"' . ($timelapseEnabled ? '' : ' hidden') . '>';
            echo '<div class="form-row"><label>' . self::t('cameras.timelapseFramesPerHour', 'Кадров в час') . '<input name="timelapse_frames_per_hour" type="number" min="1" value="' . Util::h(self::cameraPositiveInt($form['timelapse_frames_per_hour'] ?? 60, 60)) . '"></label><label>' . self::t('cameras.timelapseRetentionDays', 'Хранение timelapse') . '<input name="timelapse_retention_days" value="' . Util::h($form['timelapse_retention_days'] ?? '') . '" placeholder="30d"></label></div>';
            echo '<label>' . self::t('cameras.timelapsePlaybackFps', 'FPS воспроизведения') . '<input name="timelapse_playback_fps" type="number" min="1" value="' . Util::h(self::cameraPositiveInt($form['timelapse_playback_fps'] ?? 25, 25)) . '"></label>';
            echo '</div>';
            echo '<div class="form-row"><label>' . self::t('cameras.timelineRepairMode', 'MP4 timeline repair') . '<select name="direct_archive_video_timeline_repair_mode">';
            echo '<option value="" ' . ($timelineRepairMode === '' ? 'selected' : '') . '>' . self::t('cameras.timelineRepairDefault', 'По умолчанию') . '</option>';
            echo '<option value="auto" ' . ($timelineRepairMode === 'auto' ? 'selected' : '') . '>auto</option>';
            echo '<option value="always" ' . ($timelineRepairMode === 'always' ? 'selected' : '') . '>always</option>';
            echo '<option value="off" ' . ($timelineRepairMode === 'off' ? 'selected' : '') . '>off</option>';
            echo '</select></label><label>' . self::t('cameras.audioCodec', 'Аудиокодек') . '<select name="audio_codec">';
            echo '<option value="copy" ' . ($audioCodec === 'copy' ? 'selected' : '') . '>' . self::t('cameras.audioCodecCopy', 'Копировать') . '</option>';
            echo '<option value="aac" ' . ($audioCodec === 'aac' ? 'selected' : '') . '>' . self::t('cameras.audioCodecAac', 'Транскодировать AAC') . '</option>';
            echo '</select></label></div></details>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($form['blocked']) ? 'checked' : '') . '> ' . self::t('cameras.blocked', 'Заблокирована') . '</label>';
            self::folderCheckboxTree(self::t('cameras.folders', 'Папки'), 'folder_ids[]', $folders, $linkedFolders);
            echo '<button class="primary">' . self::t('action.saveSync', 'Сохранить и синхронизировать') . '</button></form></details>';
            if ($edit) {
                $editHasToken = trim((string)($edit['permanent_token_hash'] ?? '')) !== '';
                self::smallPostFormOpen(
                    'camera-token-issue-form',
                    '/admin/cameras',
                    ['action' => 'issue_camera_token', 'id' => (int)$edit['id']],
                    $editHasToken ? self::t('token.cameraTokenReplaceConfirm', 'Старый токен перестанет работать. Выпустить новый токен?') : ''
                );
                self::smallPostFormClose();
                if ($editHasToken) {
                    self::smallPostFormOpen('camera-token-revoke-form', '/admin/cameras', ['action' => 'revoke_camera_token', 'id' => (int)$edit['id']]);
                    self::smallPostFormClose();
                }
            }
            self::table(self::t('cameras.title', 'Камеры'), ['name', 'server_name', 'dvr_control_mode', 'agent_id', 'agent_camera_id', 'retention_days', 'archive_enabled', 'last_sync_message'], $cameras, '/admin/cameras', true, $list);
            echo '</div>';
        });
    }

    private static function cameraImport(): void
    {
        Auth::requireAdmin();
        $servers = Repo::all('dvr_servers', 'name ASC');
        $selectedServerId = (int)(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            ? Util::post('server_id', 0)
            : ($_GET['server_id'] ?? 0));
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)Util::post('action') === 'import') {
            $selectedNames = is_array($_POST['stream_names'] ?? null) ? $_POST['stream_names'] : [];
            if ($selectedServerId <= 0) {
                $message = self::t('cameras.importChooseServer', 'Выберите DVR-сервер');
            } elseif ($selectedNames === []) {
                $message = self::t('cameras.importNoSelection', 'Выберите хотя бы один поток');
            } else {
                $available = self::cameraImportAvailableStreams($selectedServerId);
                if (!$available['ok']) {
                    $message = self::t('cameras.importLoadFailed', 'Не удалось загрузить потоки с DVR');
                } else {
                    $result = self::cameraImportSelectedStreams(
                        $selectedServerId,
                        $selectedNames,
                        $available['streams'],
                        is_array($_POST['folder_ids'] ?? null) ? $_POST['folder_ids'] : []
                    );
                    $message = $result['message'];
                }
            }
        }

        $available = $selectedServerId > 0
            ? self::cameraImportAvailableStreams($selectedServerId)
            : ['ok' => true, 'streams' => [], 'invalidCount' => 0, 'message' => ''];
        $folders = Repo::allFolders();

        self::layout(self::t('cameras.importTitle', 'Импорт потоков с DVR'), function () use ($servers, $selectedServerId, $available, $folders, $message) {
            self::notice($message);
            echo '<section class="panel camera-import-panel">';
            echo '<div class="section-head"><div><h2>' . self::t('cameras.importTitle', 'Импорт потоков с DVR') . '</h2><p class="muted">' . self::t('cameras.importHint', 'Выберите сервер и добавьте отсутствующие потоки в Portal. Они будут импортированы в безопасном read-only режиме без изменения конфигурации DVR.') . '</p></div>';
            echo '<a class="btn" href="/admin/cameras">' . self::t('action.back', 'Назад') . '</a></div>';

            echo '<form method="get" action="/admin/cameras/import" class="camera-import-server-form">';
            echo '<label>' . self::t('cameras.importServer', 'DVR-сервер') . '<select name="server_id" required><option value="">' . self::t('cameras.importChooseServer', 'Выберите DVR-сервер') . '</option>';
            foreach ($servers as $server) {
                $blocked = (int)($server['blocked'] ?? 0) === 1;
                echo '<option value="' . (int)$server['id'] . '"' . ($selectedServerId === (int)$server['id'] ? ' selected' : '') . ($blocked ? ' disabled' : '') . '>' . Util::h($server['name']) . ($blocked ? ' · ' . self::t('servers.blocked', 'Заблокирован') : '') . '</option>';
            }
            echo '</select></label><button>' . self::t('cameras.loadStreams', 'Показать потоки') . '</button></form>';

            if ($selectedServerId > 0 && !$available['ok']) {
                echo '<div class="alert">' . self::t('cameras.importLoadFailed', 'Не удалось загрузить потоки с DVR') . '</div>';
                self::technicalResult((string)($available['message'] ?? ''), self::t('agents.details', 'Технические детали'));
            } elseif ($selectedServerId > 0 && !$available['streams']) {
                echo '<div class="camera-import-empty">' . self::t('cameras.importEmpty', 'На выбранном DVR нет потоков, отсутствующих в Portal') . '</div>';
            } elseif ($selectedServerId > 0) {
                if ((int)($available['invalidCount'] ?? 0) > 0) {
                    echo '<div class="alert warn">' . sprintf(self::t('cameras.importSkippedInvalid', 'Пропущено потоков с неподдерживаемым техническим именем: %d'), (int)$available['invalidCount']) . '</div>';
                }
                echo '<form method="post" action="/admin/cameras/import?server_id=' . $selectedServerId . '" class="camera-import-form" data-dvr-import-form data-submit-progress="' . Util::h(self::t('cameras.importing', 'Импортируем потоки...')) . '">' . Csrf::field();
                echo '<input type="hidden" name="action" value="import"><input type="hidden" name="server_id" value="' . $selectedServerId . '">';
                echo '<div class="camera-import-toolbar"><input type="search" data-dvr-import-search placeholder="' . self::t('cameras.importSearch', 'Найти поток') . '">';
                echo '<button type="button" data-dvr-import-select-all>' . self::t('groups.selectAll', 'Выбрать все') . '</button>';
                echo '<button type="button" data-dvr-import-clear-all>' . self::t('groups.clearAll', 'Снять все') . '</button>';
                echo '<span class="camera-import-count" data-dvr-import-count></span></div>';
                echo '<div class="camera-import-list">';
                foreach ($available['streams'] as $stream) {
                    $name = (string)$stream['name'];
                    $displayName = (string)$stream['displayName'];
                    $search = trim($displayName . ' ' . $name . ' ' . (string)$stream['sourceType']);
                    echo '<label class="camera-import-row" data-dvr-import-row data-search="' . Util::h($search) . '">';
                    echo '<input type="checkbox" name="stream_names[]" value="' . Util::h($name) . '">';
                    echo '<span class="camera-import-identity"><strong>' . Util::h($displayName) . '</strong><code>' . Util::h($name) . '</code></span>';
                    echo '<span class="camera-import-meta"><span>' . Util::h((string)$stream['sourceType']) . '</span>';
                    echo '<span class="pill ' . (!empty($stream['archiveEnabled']) ? 'success' : 'info') . '">' . (!empty($stream['archiveEnabled']) ? self::t('cameraFilter.archiveOn', 'Архив включён') : self::t('cameraFilter.archiveOff', 'Архив выключен')) . '</span>';
                    echo '<span class="pill ' . (!empty($stream['enabled']) ? 'success' : 'warn') . '">' . (!empty($stream['enabled']) ? self::t('cameras.importEnabled', 'Включён') : self::t('cameras.importDisabled', 'Выключен')) . '</span></span>';
                    echo '</label>';
                }
                echo '<div class="camera-import-filter-empty" data-dvr-import-filter-empty hidden>' . self::t('assignment.empty', 'Ничего не найдено') . '</div></div>';
                echo '<p class="muted">' . self::t('cameras.importReadOnlyHint', 'Импорт не меняет потоки на DVR. После импорта режим отдельной камеры можно изменить в её настройках.') . '</p>';
                self::folderCheckboxTree(self::t('cameras.folders', 'Папки'), 'folder_ids[]', $folders, []);
                echo '<div class="camera-import-actions"><button class="primary" data-dvr-import-submit data-submit-button disabled>' . self::t('cameras.importAction', 'Добавить выбранные потоки') . '</button><span class="muted" data-submit-status hidden></span></div>';
                echo '</form>';
            }
            echo '</section>';
        });
    }

    private static function cameraImportAvailableStreams(int $serverId): array
    {
        $result = DvrClient::listStreams($serverId);
        if (empty($result['ok'])) {
            return ['ok' => false, 'streams' => [], 'invalidCount' => 0, 'message' => (string)($result['message'] ?? '')];
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || (!array_is_list($data) && !is_array($data['streams'] ?? null))) {
            return ['ok' => false, 'streams' => [], 'invalidCount' => 0, 'message' => 'Invalid SesameDVR /api/streams response'];
        }
        $rows = array_is_list($data) ? $data : $data['streams'];
        $stmt = DB::pdo()->prepare('SELECT dvr_stream_name FROM cameras WHERE server_id = ?');
        $stmt->execute([$serverId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $existing[(string)$name] = true;
        }

        $streams = [];
        $seen = [];
        $invalidCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '' || isset($seen[$name]) || isset($existing[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (!Util::isDvrStreamName($name)) {
                $invalidCount++;
                continue;
            }
            $displayName = trim((string)($row['displayName'] ?? $row['title'] ?? '')) ?: $name;
            $streams[] = [
                'name' => $name,
                'displayName' => $displayName,
                'source' => is_scalar($row['source'] ?? null) ? (string)$row['source'] : '',
                'sourceType' => trim((string)($row['sourceType'] ?? 'direct')) ?: 'direct',
                'enabled' => self::cameraImportBool($row['enabled'] ?? true, true),
                'archiveEnabled' => self::cameraImportBool($row['archiveEnabled'] ?? true, true),
                'retentionDays' => is_scalar($row['retentionDays'] ?? null) ? (string)$row['retentionDays'] : '7d',
                'webrtcFastStart' => self::cameraImportBool($row['webrtcFastStart'] ?? false, false),
                'eventArchiveRetentionEnabled' => self::cameraImportBool($row['eventArchiveRetentionEnabled'] ?? false, false),
                'eventArchiveMaxBytes' => $row['eventArchiveMaxBytes'] ?? null,
                'eventArchiveMaxDuration' => $row['eventArchiveMaxDuration'] ?? null,
                'eventArchiveMaxAge' => $row['eventArchiveMaxAge'] ?? null,
                'timelapseEnabled' => self::cameraImportBool($row['timelapseEnabled'] ?? false, false),
                'timelapseFramesPerHour' => $row['timelapseFramesPerHour'] ?? 60,
                'timelapseRetentionDays' => $row['timelapseRetentionDays'] ?? null,
                'timelapsePlaybackFps' => $row['timelapsePlaybackFps'] ?? 25,
                'directArchiveVideoTimelineRepairMode' => $row['directArchiveVideoTimelineRepairMode'] ?? null,
                'audioCodec' => $row['audioCodec'] ?? 'copy',
            ];
        }

        usort($streams, static function (array $left, array $right): int {
            return strnatcasecmp($left['displayName'], $right['displayName'])
                ?: strnatcasecmp($left['name'], $right['name']);
        });
        return ['ok' => true, 'streams' => $streams, 'invalidCount' => $invalidCount, 'message' => ''];
    }

    private static function cameraImportSelectedStreams(int $serverId, array $selectedNames, array $availableStreams, array $folderIds): array
    {
        $server = Repo::server($serverId);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'message' => self::t('cameras.importChooseServer', 'Выберите DVR-сервер')];
        }

        $availableByName = [];
        foreach ($availableStreams as $stream) {
            $availableByName[(string)$stream['name']] = $stream;
        }
        $selectedNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => is_scalar($name) ? trim((string)$name) : '',
            $selectedNames
        ))));
        $selected = [];
        foreach ($selectedNames as $name) {
            if (isset($availableByName[$name])) {
                $selected[] = $availableByName[$name];
            }
        }
        if (!$selected) {
            return ['ok' => false, 'message' => self::t('cameras.importNoSelection', 'Выберите хотя бы один поток')];
        }

        $pdo = DB::pdo();
        $usedNames = [];
        foreach ($pdo->query('SELECT name FROM cameras')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $usedNames[self::cameraImportNameKey((string)$name)] = true;
        }
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn(int $id): bool => $id > 0)));
        $importedNames = [];

        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, archive_enabled, webrtc_fast_start, event_archive_retention_enabled, event_archive_max_bytes, event_archive_max_duration, event_archive_max_age, timelapse_enabled, timelapse_frames_per_hour, timelapse_retention_days, timelapse_playback_fps, direct_archive_video_timeline_repair_mode, audio_codec, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, onvif_host, onvif_port, onvif_username, onvif_password, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, last_sync_at, last_sync_ok, last_sync_message, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $exists = $pdo->prepare('SELECT 1 FROM cameras WHERE server_id = ? AND dvr_stream_name = ? LIMIT 1');
            $syncMessage = self::t('cameras.readOnlySyncSkipped', 'Read-only mode: DVR management skipped');
            foreach ($selected as $stream) {
                $exists->execute([$serverId, (string)$stream['name']]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                $cameraName = self::cameraImportUniqueName((string)$stream['displayName'], (string)$stream['name'], (string)$server['name'], $usedNames);
                $now = Util::now();
                $insert->execute([
                    $cameraName,
                    (string)$stream['source'],
                    $serverId,
                    'manual',
                    null,
                    null,
                    0,
                    60,
                    trim((string)$stream['retentionDays']) ?: '7d',
                    !empty($stream['archiveEnabled']) ? 1 : 0,
                    !empty($stream['webrtcFastStart']) ? 1 : 0,
                    !empty($stream['eventArchiveRetentionEnabled']) ? 1 : 0,
                    self::cameraOptionalNonNegativeInt($stream['eventArchiveMaxBytes']),
                    self::cameraOptionalString($stream['eventArchiveMaxDuration']),
                    self::cameraOptionalString($stream['eventArchiveMaxAge']),
                    !empty($stream['timelapseEnabled']) ? 1 : 0,
                    self::cameraPositiveInt($stream['timelapseFramesPerHour'], 60),
                    self::cameraOptionalString($stream['timelapseRetentionDays']),
                    self::cameraPositiveInt($stream['timelapsePlaybackFps'], 25),
                    self::cameraTimelineRepairMode($stream['directArchiveVideoTimelineRepairMode']),
                    self::cameraAudioCodec($stream['audioCodec']),
                    'read_only',
                    null,
                    null,
                    0,
                    '',
                    80,
                    '',
                    '',
                    0,
                    16,
                    0,
                    (string)$stream['name'],
                    $now,
                    1,
                    $syncMessage,
                    $now,
                    $now,
                ]);
                $cameraId = DB::lastInsertId('cameras');
                self::replaceLinks('camera_folders', 'camera_id', $cameraId, 'folder_id', $folderIds);
                $importedNames[] = (string)$stream['name'];
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SesamePortal camera import failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => self::t('cameras.importFailed', 'Не удалось импортировать выбранные потоки')];
        }

        Audit::log('camera.import', json_encode([
            'serverId' => $serverId,
            'server' => (string)$server['name'],
            'count' => count($importedNames),
            'streams' => array_slice($importedNames, 0, 50),
            'truncated' => count($importedNames) > 50,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'ok' => true,
            'message' => sprintf(self::t('cameras.importDone', 'Добавлено потоков: %d'), count($importedNames)),
        ];
    }

    private static function cameraImportUniqueName(string $displayName, string $streamName, string $serverName, array &$usedNames): string
    {
        $base = trim($displayName) ?: $streamName;
        $candidate = $base;
        $suffix = trim($serverName) ?: 'DVR';
        $number = 1;
        while (isset($usedNames[self::cameraImportNameKey($candidate)])) {
            $candidate = $base . ' · ' . $suffix . ($number > 1 ? ' ' . $number : '');
            $number++;
        }
        $usedNames[self::cameraImportNameKey($candidate)] = true;
        return $candidate;
    }

    private static function cameraImportNameKey(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private static function cameraImportBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function cameraControlMode(mixed $value): string
    {
        $mode = trim((string)$value);
        return in_array($mode, ['managed', 'edge_agent', 'read_only'], true) ? $mode : 'managed';
    }

    private static function cameraTimelineRepairMode(mixed $value): ?string
    {
        $mode = strtolower(trim((string)$value));
        return in_array($mode, ['auto', 'always', 'off'], true) ? $mode : null;
    }

    private static function cameraAudioCodec(mixed $value): string
    {
        return strtolower(trim((string)$value)) === 'aac' ? 'aac' : 'copy';
    }

    private static function cameraPositiveInt(mixed $value, int $default): int
    {
        $value = (int)$value;
        return $value > 0 ? $value : $default;
    }

    private static function cameraOptionalNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return max(0, (int)$value);
    }

    private static function cameraOptionalMegabytesAsBytes(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $megabytes = max(0.0, (float)str_replace(',', '.', trim((string)$value)));
        return (int)ceil($megabytes * 1024 * 1024);
    }

    private static function cameraEventArchiveMaxBytesFromPost(): ?int
    {
        if (array_key_exists('event_archive_max_mb', $_POST)) {
            return self::cameraOptionalMegabytesAsBytes(Util::post('event_archive_max_mb'));
        }
        return self::cameraOptionalNonNegativeInt(Util::post('event_archive_max_bytes'));
    }

    private static function eventArchiveBytesToMegabytesInput(mixed $value): string
    {
        $bytes = self::cameraOptionalNonNegativeInt($value);
        if ($bytes === null) {
            return '';
        }
        $text = number_format($bytes / 1024 / 1024, 2, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }

    private static function cameraOptionalString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function watermarkIntensity(mixed $value): int
    {
        $intensity = (int)$value;
        return max(1, min(100, $intensity > 0 ? $intensity : 16));
    }

    private static function cameraNamesFromInput(array $input, ?array $current): array
    {
        $displayValue = self::firstInputValue(
            $input,
            ['displayName', 'display_name', 'name'],
            $current['name'] ?? ''
        );
        $streamValue = self::firstInputValue(
            $input,
            ['dvrStreamName', 'dvr_stream_name', 'streamName', 'stream_name'],
            $current['dvr_stream_name'] ?? ''
        );

        $displayName = trim((string)$displayValue);
        $streamName = trim((string)$streamValue);
        if ($streamName === '' && $displayName !== '') {
            $streamName = Util::dvrStreamSlug($displayName) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        if ($displayName === '' && $streamName !== '') {
            $displayName = $streamName;
        }

        return [$displayName, $streamName];
    }

    private static function firstInputValue(array $input, array $keys, mixed $fallback = ''): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }
        }
        return $fallback;
    }

    private static function cameraSaveNotice(array $sync): string
    {
        return !empty($sync['ok'])
            ? self::t('cameras.saveDone', 'Камера сохранена')
            : self::t('cameras.saveSyncFailed', 'Камера сохранена, но синхронизация с DVR не выполнена');
    }

    private static function cameraSyncNotice(array $sync): string
    {
        return !empty($sync['ok'])
            ? self::t('cameras.syncDone', 'Синхронизация выполнена')
            : self::t('cameras.syncFailed', 'Синхронизация не выполнена');
    }

    private static function cameraFormDefaults(?array $edit): array
    {
        if ($edit) {
            return $edit;
        }

        [$name, $stream] = self::cameraNamesFromInput([
            'display_name' => $_GET['display_name'] ?? $_GET['displayName'] ?? $_GET['name'] ?? '',
            'dvr_stream_name' => $_GET['stream'] ?? $_GET['dvr_stream_name'] ?? $_GET['dvrStreamName'] ?? '',
        ], null);
        $serverId = (int)($_GET['server_id'] ?? 0) ?: '';
        $controlMode = self::cameraControlMode($_GET['mode'] ?? $_GET['dvr_control_mode'] ?? 'managed');
        $serverSelection = $controlMode === 'edge_agent' || $serverId !== '' ? 'manual' : 'auto';
        if (isset($_GET['server_selection'])) {
            $serverSelection = $_GET['server_selection'] === 'auto' && $controlMode !== 'edge_agent' ? 'auto' : 'manual';
        }

        return [
            'name' => $name,
            'source_url' => (string)($_GET['source_url'] ?? ''),
            'server_id' => $serverId,
            'server_selection' => $serverSelection,
            'latitude' => '',
            'longitude' => '',
            'direction_deg' => 0,
            'view_angle_deg' => 60,
            'retention_days' => (string)($_GET['retention_days'] ?? '7d'),
            'archive_enabled' => array_key_exists('archive_enabled', $_GET) ? (int)!empty($_GET['archive_enabled']) : 1,
            'webrtc_fast_start' => !empty($_GET['webrtc_fast_start']) ? 1 : 0,
            'event_archive_retention_enabled' => !empty($_GET['event_archive_retention_enabled']) ? 1 : 0,
            'event_archive_max_bytes' => array_key_exists('event_archive_max_mb', $_GET)
                ? self::cameraOptionalMegabytesAsBytes($_GET['event_archive_max_mb'])
                : self::cameraOptionalNonNegativeInt($_GET['event_archive_max_bytes'] ?? null),
            'event_archive_max_duration' => self::cameraOptionalString($_GET['event_archive_max_duration'] ?? ''),
            'event_archive_max_age' => self::cameraOptionalString($_GET['event_archive_max_age'] ?? ''),
            'timelapse_enabled' => !empty($_GET['timelapse_enabled']) ? 1 : 0,
            'timelapse_frames_per_hour' => self::cameraPositiveInt($_GET['timelapse_frames_per_hour'] ?? 60, 60),
            'timelapse_retention_days' => self::cameraOptionalString($_GET['timelapse_retention_days'] ?? ''),
            'timelapse_playback_fps' => self::cameraPositiveInt($_GET['timelapse_playback_fps'] ?? 25, 25),
            'direct_archive_video_timeline_repair_mode' => self::cameraTimelineRepairMode($_GET['direct_archive_video_timeline_repair_mode'] ?? null),
            'audio_codec' => self::cameraAudioCodec($_GET['audio_codec'] ?? 'copy'),
            'dvr_control_mode' => $controlMode,
            'agent_id' => (string)($_GET['agent_id'] ?? ''),
            'agent_camera_id' => (string)($_GET['agent_camera_id'] ?? ''),
            'onvif_events_requested' => !empty($_GET['onvif_events_requested']) ? 1 : 0,
            'onvif_host' => (string)($_GET['onvif_host'] ?? ''),
            'onvif_port' => max(1, min(65535, (int)($_GET['onvif_port'] ?? 80))),
            'onvif_username' => (string)($_GET['onvif_username'] ?? ''),
            'onvif_password' => (string)($_GET['onvif_password'] ?? ''),
            'watermark_enabled' => !empty($_GET['watermark_enabled']) ? 1 : 0,
            'watermark_intensity' => self::watermarkIntensity($_GET['watermark_intensity'] ?? 16),
            'blocked' => 0,
            'dvr_stream_name' => $stream,
        ];
    }

    private static function cameraDeleteCandidate(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare('SELECT c.*, s.name AS server_name, s.base_url AS server_base_url, s.blocked AS server_blocked, s.management_token_enc AS server_management_token_enc
            FROM cameras c
            LEFT JOIN dvr_servers s ON s.id = c.server_id
            WHERE c.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function cameraDeletePanel(array $camera): void
    {
        $stream = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        $canDeleteDvr = !empty($camera['server_id'])
            && (int)($camera['server_blocked'] ?? 0) === 0
            && ($camera['dvr_control_mode'] ?? 'managed') !== 'read_only'
            && trim((string)($camera['server_management_token_enc'] ?? '')) !== ''
            && $stream !== '';

        echo '<section class="panel delete-confirm"><div class="section-head"><h2>' . self::t('cameras.deleteTitle', 'Удалить камеру') . '</h2><a href="/admin/cameras">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '<div class="alert warn">';
        echo '<strong>' . self::t('cameras.deleteWarning', 'Это действие нельзя отменить.') . '</strong> ';
        echo self::t('cameras.deleteWarningText', 'Сначала подтвердите удаление камеры из портала. Отдельным флажком можно удалить связанный поток на DVR вместе с архивом.');
        echo '</div>';
        echo '<dl class="delete-meta">';
        echo '<dt>' . self::t('cameras.name', 'Имя') . '</dt><dd>' . Util::h($camera['name']) . '</dd>';
        echo '<dt>' . self::t('cameras.streamName', 'Имя потока SesameDVR') . '</dt><dd>' . Util::h($stream ?: '-') . '</dd>';
        echo '<dt>' . self::t('cameras.server', 'Сервер') . '</dt><dd>' . Util::h($camera['server_name'] ?: '-') . '</dd>';
        echo '</dl>';
        echo '<form method="post" action="/admin/cameras" class="form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$camera['id'] . '">';
        echo '<label class="check"><input type="checkbox" name="confirm_delete" required> ' . self::t('cameras.confirmDelete', 'Подтверждаю удаление камеры из портала') . '</label>';
        if ($canDeleteDvr) {
            echo '<label class="check"><input type="checkbox" name="delete_dvr_stream"> ' . self::t('cameras.deleteDvrStream', 'Также удалить поток на DVR и очистить архив, превью и индексы') . '</label>';
        } else {
            echo '<p class="muted">' . self::t('cameras.deleteDvrUnavailable', 'Удаление потока на DVR недоступно для этой камеры: проверьте сервер, token управления и режим управления.') . '</p>';
        }
        echo '<div class="form-actions"><button class="danger">' . self::t('action.delete', 'Удалить') . '</button><a href="/admin/cameras">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '</form></section>';
    }

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
}
