<?php

namespace teachmeter\mediaproxy;

use Craft;
use craft\console\controllers\IndexAssetsController;
use craft\controllers\AssetIndexesController;
use craft\controllers\AssetsController;
use craft\elements\Asset;
use craft\events\DefineAssetUrlEvent;
use craft\helpers\App;
use craft\helpers\Image;
use Throwable;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\console\Exception as ConsoleException;
use yii\web\ForbiddenHttpException;

/**
 * Serves assets whose file is missing locally from a remote site (e.g. production).
 * Loaded automatically as a Yii extension, so it needs no plugin install and leaves no trace in project config.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * Local URL prefix for files streamed through PHP (only used with basic auth).
     * ponytail: root-relative, assumes Craft runs at the web root. Prefix with @web if a project ever needs a subfolder.
     */
    private const STREAM_PREFIX = '/media-proxy';

    /** Remote site URL without trailing slash. */
    private string $remote = '';

    /** "user:password" for a remote protected by HTTP basic auth, or ''. */
    private string $credentials = '';

    /** @var array<int|string, bool> Per-request cache: asset id => file is missing locally. */
    private array $missing = [];

    public function bootstrap($app): void
    {
        $this->remote = self::remoteUrl();
        if ($this->remote === '') {
            return;
        }

        $this->credentials = (string) App::env('MEDIA_PROXY_BASIC_AUTH');

        // Runs before Craft touches transforms, so nothing is generated (or queued) locally for remote assets.
        // Covers templates, CP thumbnails and previews, as they all end up in Asset::getUrl().
        Event::on(Asset::class, Asset::EVENT_BEFORE_DEFINE_URL, function (DefineAssetUrlEvent $event) {
            /** @var Asset $asset */
            $asset = $event->sender;
            $url = $this->isRemote($asset) ? $this->assetUrl($asset, $event->transform) : null;

            if ($url !== null) {
                $event->url = $url;
                $event->handled = true;
            }
        });

        // The image editor needs the local file.
        Event::on(AssetsController::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) {
            if (!in_array($event->action->id, ['image-editor', 'edit-image', 'save-image'], true)) {
                return;
            }

            $asset = Craft::$app->getAssets()->getAssetById((int) Craft::$app->getRequest()->getParam('assetId'));

            if ($asset && $this->isRemote($asset)) {
                throw new ForbiddenHttpException('This image is served from the remote site and cannot be edited locally.');
            }
        });

        // Indexing would flag every remote file as missing and offer to delete its asset.
        $indexingMessage = 'Asset indexing is disabled while the media proxy is active: files served from the remote site would be treated as missing.';

        Event::on(AssetIndexesController::class, Controller::EVENT_BEFORE_ACTION, function () use ($indexingMessage) {
            throw new ForbiddenHttpException($indexingMessage);
        });

        Event::on(IndexAssetsController::class, Controller::EVENT_BEFORE_ACTION, function () use ($indexingMessage) {
            throw new ConsoleException($indexingMessage);
        });

        if ($this->credentials !== '') {
            $app->on(Application::EVENT_BEFORE_REQUEST, fn() => $this->serveStreamRequest());
        }
    }

    /**
     * Remote site URL without trailing slash, or '' when the proxy is disabled.
     */
    private static function remoteUrl(): string
    {
        // Opt-in: off unless explicitly enabled.
        if (!filter_var(App::env('MEDIA_PROXY_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        $url = rtrim((string) App::env('MEDIA_PROXY_REMOTE_URL'), '/');
        $host = parse_url($url, PHP_URL_HOST);
        $currentHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);

        // Never point at ourselves: on the remote site itself every asset would proxy to itself.
        if (!$host || $host === $currentHost) {
            return '';
        }

        return $url;
    }

    /**
     * True when the asset's file does not exist locally (so it lives on the remote).
     */
    private function isRemote(Asset $asset): bool
    {
        $key = $asset->id ?? spl_object_id($asset);

        if (!isset($this->missing[$key])) {
            try {
                $this->missing[$key] = $asset->folderId && !$asset->getVolume()->fileExists($asset->getPath());
            } catch (Throwable) {
                // Temp uploads, broken volume config etc.: let Craft handle the asset as usual.
                $this->missing[$key] = false;
            }
        }

        return $this->missing[$key];
    }

    /**
     * Remote URL for an asset. Transforms the remote has already generated are used as they are,
     * all others fall back to the original image (scaled by the browser).
     */
    private function assetUrl(Asset $asset, mixed $transform): ?string
    {
        $rootUrl = $asset->getVolume()->getRootUrl();
        if ($rootUrl === null) {
            return null;
        }

        $path = $transform && Image::canManipulateAsImage($asset->getExtension())
            ? (new TransformPath())->find($asset, $transform)
            : null;

        if ($path === null) {
            $segments = explode('/', $asset->folderPath . $asset->getFilename());
            $path = rtrim((string) parse_url($rootUrl, PHP_URL_PATH), '/') . '/' . implode('/', array_map('rawurlencode', $segments));
        }

        return ($this->credentials !== '' ? self::STREAM_PREFIX : $this->remote) . $path;
    }

    /**
     * Browsers cannot send credentials for a remote URL, so with basic auth
     * remote files are linked as /media-proxy/<path> and streamed through PHP.
     */
    private function serveStreamRequest(): void
    {
        $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!str_starts_with($requestPath, self::STREAM_PREFIX . '/')) {
            return;
        }

        $path = substr($requestPath, strlen(self::STREAM_PREFIX));

        // Only proxy files below an asset volume or transform root, never arbitrary remote pages.
        if (str_contains(rawurldecode($path), '..') || !$this->isVolumePath($path)) {
            http_response_code(404);
            exit;
        }

        $this->stream($this->remote . $path);
    }

    private function isVolumePath(string $path): bool
    {
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            foreach ([$volume->getRootUrl(), $volume->getTransformFs()->getRootUrl()] as $rootUrl) {
                $rootPath = rtrim((string) parse_url((string) $rootUrl, PHP_URL_PATH), '/') . '/';

                if ($rootUrl !== null && str_starts_with($path, $rootPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pass a remote file through to the client without storing it locally.
     * Forwards Range requests so video seeking works.
     */
    private function stream(string $url): void
    {
        $requestHeaders = ['Authorization: Basic ' . base64_encode($this->credentials)];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        $context = stream_context_create(['http' => ['header' => $requestHeaders, 'timeout' => 30]]);
        $remote = @fopen($url, 'rb', false, $context);

        if (!$remote) {
            http_response_code(404);
            exit;
        }

        // wrapper_data holds the headers of every response including redirects; only the last one counts.
        $status = 200;
        $forward = [];
        foreach (stream_get_meta_data($remote)['wrapper_data'] as $line) {
            if (stripos($line, 'HTTP/') === 0) {
                $status = (int) (explode(' ', $line)[1] ?? 200);
                $forward = [];
            } elseif (preg_match('/^(content-type|content-length|content-range|accept-ranges|last-modified|etag):/i', $line)) {
                $forward[] = $line;
            }
        }

        set_time_limit(0);
        http_response_code($status);
        header('Cache-Control: no-store');
        foreach ($forward as $line) {
            header($line);
        }

        fpassthru($remote);
        fclose($remote);
        exit;
    }
}
