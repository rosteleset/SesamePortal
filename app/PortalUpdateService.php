<?php

declare(strict_types=1);

namespace SesamePortal;

use RuntimeException;
final class PortalUpdateService
{
    public static function status(bool $force = false, bool $allowRefresh = true): array
    {
        $enabled = (bool)Config::get('portal_update_enabled', true);
        $current = self::currentRelease();
        $cache = self::readCache();
        $latest = is_array($cache['latest'] ?? null) ? $cache['latest'] : null;
        $checkedAt = is_string($cache['checkedAt'] ?? null) ? $cache['checkedAt'] : null;
        $checkError = is_string($cache['error'] ?? null) ? $cache['error'] : null;

        if ($enabled && $allowRefresh && self::shouldRefresh($cache, $force)) {
            try {
                $latest = self::fetchLatest();
                $checkedAt = Util::now();
                $checkError = null;
                self::writeCache([
                    'checkedAt' => $checkedAt,
                    'latest' => $latest,
                    'error' => null,
                ]);
            } catch (\Throwable $error) {
                $checkedAt = Util::now();
                $checkError = $error->getMessage();
                self::writeCache([
                    'checkedAt' => $checkedAt,
                    'latest' => $latest,
                    'error' => $checkError,
                ]);
            }
        }

        $currentCommit = self::normalizeSha((string)($current['sourceCommit'] ?? ''));
        $latestCommit = self::normalizeSha((string)($latest['sourceCommit'] ?? ''));
        $updateAvailable = $enabled && $currentCommit !== '' && $latestCommit !== '' && !hash_equals($currentCommit, $latestCommit);

        return [
            'enabled' => $enabled,
            'repo' => self::githubRepo(),
            'ref' => self::githubRef(),
            'current' => $current,
            'latest' => $latest,
            'checkedAt' => $checkedAt,
            'checkError' => $checkError,
            'stale' => self::cacheIsStale($cache),
            'updateAvailable' => $updateAvailable,
            'upToDate' => $enabled && $currentCommit !== '' && $latestCommit !== '' && hash_equals($currentCommit, $latestCommit),
            'toolInstalled' => self::toolInstalled(),
            'command' => (string)Config::get('portal_update_command', ''),
        ];
    }

    public static function cachedStatus(): array
    {
        return self::status(false, false);
    }

    public static function run(): array
    {
        if (!(bool)Config::get('portal_update_enabled', true)) {
            return ['ok' => false, 'status' => 1, 'output' => 'Portal updates are disabled'];
        }

        $command = trim((string)Config::get('portal_update_command', 'sudo -n /usr/local/sbin/sesame-portal-update'));
        if ($command === '') {
            return ['ok' => false, 'status' => 1, 'output' => 'Portal update command is not configured'];
        }
        if (!function_exists('exec')) {
            return ['ok' => false, 'status' => 1, 'output' => 'PHP exec() is disabled'];
        }

        $repo = self::githubRepo();
        $ref = self::githubRef();
        $fullCommand = $command;
        if ((bool)Config::get('portal_update_pass_args', false)) {
            $args = [
                '--repo', $repo,
                '--ref', $ref,
                '--install-link', self::installLink(),
                '--state-dir', Config::stateDir(),
                '--php-bin', PHP_BINARY ?: 'php',
            ];
            foreach ($args as $arg) {
                $fullCommand .= ' ' . escapeshellarg((string)$arg);
            }
        }
        $fullCommand .= ' 2>&1';

        Audit::log('portal.update.start', 'repo=' . Audit::cleanValue($repo) . ' ref=' . Audit::cleanValue($ref) . ' ip=' . Audit::clientIp());
        $lines = [];
        $status = 1;
        exec($fullCommand, $lines, $status);
        $output = mb_strcut(implode("\n", $lines), 0, 5000, 'UTF-8');
        $details = 'repo=' . Audit::cleanValue($repo) . ' ref=' . Audit::cleanValue($ref) . ' rc=' . $status . ' ip=' . Audit::clientIp();
        Audit::log($status === 0 ? 'portal.update.complete' : 'portal.update.failed', $details);
        if ($status === 0) {
            @unlink(self::cachePath());
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        }

        return [
            'ok' => $status === 0,
            'status' => $status,
            'output' => $output,
        ];
    }

    public static function currentRelease(): array
    {
        $root = Config::root();
        $releaseFile = $root . '/RELEASE.json';
        $data = is_file($releaseFile) ? json_decode((string)file_get_contents($releaseFile), true) : [];
        $data = is_array($data) ? $data : [];

        $deployedRevision = $root . '/.deployed-revision';
        if (empty($data['sourceCommit']) && is_file($deployedRevision)) {
            $data['sourceCommit'] = trim((string)file_get_contents($deployedRevision));
        }
        if (empty($data['sourceCommit']) && is_dir($root . '/.git')) {
            $lines = [];
            $status = 1;
            if (function_exists('exec')) {
                exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null', $lines, $status);
                if ($status === 0 && !empty($lines[0])) {
                    $data['sourceCommit'] = trim((string)$lines[0]);
                }
                $lines = [];
                exec('git -C ' . escapeshellarg($root) . ' describe --tags --always --dirty 2>/dev/null', $lines, $status);
                if ($status === 0 && !empty($lines[0]) && empty($data['version'])) {
                    $data['version'] = trim((string)$lines[0]);
                }
            }
        }

        $data += [
            'name' => 'SesamePortal',
            'version' => 'dev',
            'sourceCommit' => '',
            'dirty' => null,
            'builtAt' => null,
        ];
        $data['root'] = $root;
        return $data;
    }

    private static function fetchLatest(): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl extension is not available');
        }

        $repo = self::githubRepo();
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            throw new RuntimeException('Invalid GitHub repo format');
        }

        $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode(self::githubRef());
        $ch = curl_init($url);
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: SesamePortal',
        ];
        $token = trim((string)Config::get('portal_update_github_token', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int)(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub check failed: HTTP ' . $status . ($error !== '' ? ' ' . $error : ''));
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['sha'])) {
            throw new RuntimeException('GitHub response does not contain commit sha');
        }

        $message = (string)($data['commit']['message'] ?? '');
        $message = strtok($message, "\n") ?: $message;
        $sha = (string)$data['sha'];
        return [
            'version' => substr($sha, 0, 12),
            'sourceCommit' => $sha,
            'commitDate' => (string)($data['commit']['committer']['date'] ?? $data['commit']['author']['date'] ?? ''),
            'message' => $message,
            'url' => (string)($data['html_url'] ?? ''),
        ];
    }

    private static function shouldRefresh(array $cache, bool $force): bool
    {
        if ($force) {
            return true;
        }
        if (!(bool)Config::get('portal_update_auto_check', true)) {
            return false;
        }
        if (!is_array($cache['latest'] ?? null)) {
            return true;
        }
        return self::cacheIsStale($cache);
    }

    private static function cacheIsStale(array $cache): bool
    {
        $checkedAt = strtotime((string)($cache['checkedAt'] ?? ''));
        if (!$checkedAt) {
            return true;
        }
        $ttl = max(60, (int)Config::get('portal_update_check_ttl_seconds', 600));
        return time() - $checkedAt > $ttl;
    }

    private static function readCache(): array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private static function writeCache(array $data): void
    {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function cachePath(): string
    {
        return rtrim(Config::stateDir(), '/') . '/portal-update-status.json';
    }

    private static function githubRepo(): string
    {
        return trim((string)Config::get('portal_update_github_repo', 'rosteleset/SesamePortal'));
    }

    private static function githubRef(): string
    {
        return trim((string)Config::get('portal_update_github_ref', 'main')) ?: 'main';
    }

    private static function installLink(): string
    {
        return trim((string)Config::get('portal_update_install_link', '')) ?: Config::root();
    }

    private static function toolInstalled(): bool
    {
        $command = (string)Config::get('portal_update_command', '');
        if (preg_match('/(?:^|\s)(\/[^\s]+sesame-portal-update)(?:\s|$)/', $command, $match)) {
            return is_executable($match[1]);
        }
        return trim($command) !== '';
    }

    private static function normalizeSha(string $sha): string
    {
        $sha = strtolower(trim($sha));
        return preg_match('/^[a-f0-9]{7,40}$/', $sha) ? $sha : '';
    }
}
