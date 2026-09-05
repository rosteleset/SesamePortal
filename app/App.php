<?php

declare(strict_types=1);

namespace SesamePortal;

final class App
{
    use AppApiTrait;
    use AppPagesTrait;
    use AppViewerTrait;
    use AppAuthBackendTrait;
    use AppRenderTrait;
    use AppDataTrait;

    private static function t(string $key, string $fallback): string
    {
        return I18n::t($key, $fallback);
    }

    public static function run(): void
    {
        DB::migrate();
        Auth::start();
        Csrf::verify();
        I18n::bootstrap();

        $path = Util::path();
        if ($path === '/api/portal/v1' || str_starts_with($path, '/api/portal/v1/')) {
            self::apiPortalV1();
            return;
        }

        match ($path) {
            '/login' => self::login(),
            '/logout' => self::logout(),
            '/onboarding' => self::onboarding(),
            '/forgot' => self::forgotPassword(),
            '/reset' => self::resetPassword(),
            '/admin/dashboard' => self::dashboard(),
            '/admin/users' => self::users(),
            '/admin/groups' => self::groups(),
            '/admin/servers' => self::servers(),
            '/admin/agents/snapshot' => self::agentSnapshotProxy(),
            '/admin/agents' => self::agents(),
            '/admin/cameras' => self::cameras(),
            '/admin/cameras/import' => self::cameraImport(),
            '/admin/audit' => self::audit(),
            '/admin/settings' => self::settings(),
            '/viewer/map' => self::viewer('map'),
            '/viewer/events' => self::events(),
            '/viewer/preview' => self::previewProxy(),
            '/viewer/player' => self::player(),
            '/favorite/toggle' => self::toggleFavorite(),
            '/camera/rename' => self::renameCamera(),
            '/theme' => self::updateTheme(),
            '/mosaic' => self::mosaics(),
            '/mosaic/new' => self::mosaicEdit(),
            '/mosaic/edit' => self::mosaicEdit(),
            '/mosaic/save' => self::mosaicSave(),
            '/mosaic/view' => self::mosaicView(),
            '/mosaic/delete' => self::mosaicDelete(),
            '/api/sesamedvr/auth' => self::authBackend(),
            default => self::viewer('mosaic'),
        };
    }
}
