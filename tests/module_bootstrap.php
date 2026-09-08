<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$unusedState = sys_get_temp_dir() . '/portal-bootstrap-' . bin2hex(random_bytes(8));
putenv('SESAME_PORTAL_STATE_DIR=' . $unusedState);
putenv('SESAME_PORTAL_DB_DSN=sqlite:' . $unusedState . '/portal.sqlite');
putenv('SESAME_PORTAL_UPDATE_AUTO_CHECK=0');
chdir(sys_get_temp_dir());

ob_start();
require $root . '/app/Portal.php';
require $root . '/app/Portal.php';
$output = ob_get_clean();
$checks = 0;
function checkModule(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

checkModule($output === '', 'Bootstrap must not produce output, including on repeated require');
checkModule(!file_exists($unusedState), 'Bootstrap must not initialize state or run migrations');
checkModule(session_status() === PHP_SESSION_NONE, 'Bootstrap must not start an HTTP session');
checkModule(SesamePortal\Config::root() === $root, 'Project root must not depend on the working directory');

foreach (['Config', 'DB', 'PortalSettings', 'MapTilesService', 'Util', 'I18n', 'Crypto', 'Audit',
    'TokenService', 'Auth', 'Csrf', 'DvrClient', 'PortalUpdateService', 'Repo', 'App', 'Cli',
    'VideoWalls', 'VideoWallTranslations', 'I18nCatalog'] as $name) {
    checkModule(class_exists('SesamePortal\\' . $name, false), 'Missing bootstrap class: ' . $name);
}
$included = array_map('realpath', get_included_files());
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        checkModule(in_array($file->getRealPath(), $included, true), 'Module is not wired into bootstrap: ' . $file->getPathname());
    }
}

$app = new ReflectionClass(SesamePortal\App::class);
$routerMethods = [];
foreach ($app->getMethods() as $method) {
    if ($method->getFileName() === $app->getFileName()) $routerMethods[] = $method->getName();
    checkModule($method->isStatic(), 'App composition must retain static methods: ' . $method->getName());
    checkModule($method->getName() === 'run' ? $method->isPublic() : $method->isPrivate(), 'App method visibility changed: ' . $method->getName());
    // Traits share App's private methods. Catch missing links without executing a request.
    $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $tokens = array_values(array_filter(token_get_all('<?php ' . $source), static fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    for ($i = 0; $i + 3 < count($tokens); $i++) {
        if (($tokens[$i][0] ?? null) === T_STRING && $tokens[$i][1] === 'self'
            && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
            && ($tokens[$i + 2][0] ?? null) === T_STRING && $tokens[$i + 3] === '(') {
            $target = $tokens[$i + 2][1];
            checkModule($app->hasMethod($target), $method->getName() . ' calls missing App::' . $target);
        }
    }
}
sort($routerMethods);
checkModule($routerMethods === ['run', 't'], 'App.php must contain only routing and translation entry points');
checkModule(isset($app->getTraits()[SesamePortal\VideoWallPages::class]), 'Existing video wall composition must remain available');

$assetUrl = $app->getMethod('assetUrl');
foreach (['styles.css', 'app.js', 'video-walls.css', 'video-walls.js', 'video-wall-playback.js'] as $asset) {
    $path = '/assets/' . $asset;
    checkModule($assetUrl->invoke(null, $path) === $path . '?v=' . filemtime($root . '/public' . $path), 'Asset version must use the project public directory: ' . $asset);
}
checkModule($assetUrl->invoke(null, '/assets/not-present.css') === '/assets/not-present.css', 'Missing asset behavior must remain unchanged');
checkModule(!file_exists($unusedState), 'Module contract checks must not initialize state');
echo "module bootstrap: $checks checks passed\n";
