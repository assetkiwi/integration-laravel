<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel;

use AssetKiwi\Laravel\Client\AssetKiwiClient;
use AssetKiwi\Laravel\Http\Middleware\VerifyWebhookSignature;
use AssetKiwi\Laravel\Storage\AssetKiwiFilesystemAdapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class AssetKiwiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/assetkiwi.php', 'assetkiwi');

        $this->app->scoped(AssetKiwiClient::class, function ($app) {
            return new AssetKiwiClient(
                apiUrl: config('assetkiwi.api_url'),
                apiToken: config('assetkiwi.api_token'),
                cacheTtl: (int) config('assetkiwi.cache_ttl', 3600),
            );
        });

        $this->app->alias(AssetKiwiClient::class, 'assetkiwi');
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerViews();
        $this->registerFilesystemDriver();
        $this->registerRoutes();
        $this->registerOAuthRoutes();
        $this->registerBladeComponents();
        $this->registerBladeDirectives();
        $this->registerCommands();
    }

    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/assetkiwi.php' => config_path('assetkiwi.php'),
            ], 'assetkiwi-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/assetkiwi'),
            ], 'assetkiwi-views');
        }
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'assetkiwi');
    }

    private function registerFilesystemDriver(): void
    {
        Storage::extend('assetkiwi', function ($app, $config) {
            $client = $app->make(AssetKiwiClient::class);
            $adapter = new AssetKiwiFilesystemAdapter($client);

            return new FilesystemAdapter(
                new Filesystem($adapter),
                $adapter,
                $config,
            );
        });
    }

    private function registerRoutes(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')
                ->group(__DIR__ . '/../routes/webhook.php');
        }
    }

    private function registerOAuthRoutes(): void
    {
        if (! $this->app->routesAreCached() && config('assetkiwi.oauth.enabled', false)) {
            Route::middleware('web')->group(function () {
                $redirectUri = config('assetkiwi.oauth.redirect_uri');
                $path = $redirectUri
                    ? trim((string) parse_url($redirectUri, PHP_URL_PATH), '/')
                    : 'assetkiwi/oauth/callback';

                Route::get($path, [\AssetKiwi\Laravel\Http\Controllers\OAuthController::class, 'callback'])
                    ->name('assetkiwi.oauth.callback');
            });
        }
    }

    private function registerBladeComponents(): void
    {
        Blade::component('assetkiwi-image', \AssetKiwi\Laravel\View\Components\Image::class);
    }

    private function registerBladeDirectives(): void
    {
        Blade::directive('asset', function ($expression) {
            return "<?php echo app('assetkiwi')->assetUrl({$expression}); ?>";
        });
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \AssetKiwi\Laravel\Console\Commands\ClearCacheCommand::class,
                \AssetKiwi\Laravel\Console\Commands\SyncUsageCommand::class,
                \AssetKiwi\Laravel\Console\Commands\StatsCommand::class,
            ]);
        }
    }
}

if (!function_exists('assetkiwi_asset')) {
    function assetkiwi_asset(string $uuid, array $options = []): ?string
    {
        return app('assetkiwi')->assetUrl($uuid, $options);
    }
}
