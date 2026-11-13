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
    use AppOpenApiSpecTrait;
    use AppOpenApiSchemasTrait;
    use AppOpenApiPathsTrait;
    use AppBrandingTrait;
    use VideoWallPages;

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

        // Public documentation: reachable without a session, next to the JSON API.
        if ($path === '/openapi.json') {
            self::openApiJsonResponse();
            return;
        }
        if ($path === '/docs.html' || $path === '/api/docs') {
            self::openApiDocsPage();
            return;
        }

        // Бренд-ассеты: публичны, т.к. нужны на экране входа и при установке PWA.
        if (str_starts_with($path, '/branding/')) {
            self::brandingAsset(substr($path, strlen('/branding/')));
            return;
        }
        if ($path === '/manifest.json') {
            self::manifestJsonResponse();
            return;
        }

        match ($path) {
            '/login' => self::login(),
            '/logout' => self::logout(),
            '/onboarding' => self::onboarding(),
            '/profile' => self::profile(),
            '/forgot' => self::forgotPassword(),
            '/reset' => self::resetPassword(),
            '/admin/dashboard' => self::dashboard(),
            '/admin/users' => self::users(),
            '/admin/groups' => self::groups(),
            '/admin/servers' => self::servers(),
            '/admin/agents/snapshot' => self::agentSnapshotProxy(),
            '/admin/agents' => self::agents(),
            '/admin/cameras' => self::cameras(),
            '/admin/cameras/onvif-probe' => self::onvifProbe(),
            '/admin/cameras/import' => self::cameraImport(),
            '/admin/audit' => self::audit(),
            '/admin/settings' => self::settings(),
            '/viewer/map' => self::viewer('map'),
            '/viewer/events' => self::events(),
            '/viewer/preview' => self::previewProxy(),
            '/viewer/player' => self::player(),
            '/viewer/player/events' => self::playerEventsJson(),
            '/favorite/toggle' => self::toggleFavorite(),
            '/camera/rename' => self::renameCamera(),
            '/theme' => self::updateTheme(),
            '/mosaic' => self::mosaics(),
            '/mosaic/new' => self::mosaicEdit(),
            '/mosaic/edit' => self::mosaicEdit(),
            '/mosaic/save' => self::mosaicSave(),
            '/mosaic/view' => self::mosaicView(),
            '/mosaic/delete' => self::mosaicDelete(),
            '/video-walls' => self::videoWallsPage(),
            '/video-walls/edit' => self::videoWallsPage(),
            '/video-walls/view' => self::videoWallsPage(),
            '/video-walls/stream' => self::videoWallsPage(),
            '/api/sesamedvr/auth' => self::authBackend(),
            default => self::viewer('mosaic'),
        };
    }
}
