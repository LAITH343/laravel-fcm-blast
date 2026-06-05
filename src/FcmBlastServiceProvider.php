<?php

namespace Laith343\FcmBlast;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Laith343\FcmBlast\Auth\TokenProvider;
use Laith343\FcmBlast\Contracts\RunReporter;
use Laith343\FcmBlast\Reporting\EloquentRunReporter;
use RuntimeException;

class FcmBlastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fcm-blast.php', 'fcm-blast');

        $this->app->singleton(TokenProvider::class, function ($app) {
            /** @var Config $config */
            $config = $app['config'];

            return new TokenProvider(
                cache: $app->make(CacheFactory::class)->store(),
                serviceAccount: $this->serviceAccount($config->get('fcm-blast.credentials')),
                cacheKey: (string) $config->get('fcm-blast.token_cache_key', 'fcm-blast:oauth'),
                refreshBufferSeconds: (int) $config->get('fcm-blast.token_refresh_buffer', 600),
            );
        });

        $this->app->bind(RunReporter::class, EloquentRunReporter::class);

        $this->app->singleton(FcmBlastManager::class, function ($app) {
            return new FcmBlastManager(
                config: $app['config'],
                reporter: $app->make(RunReporter::class),
                planner: $app->make(Dispatching\RunPlanner::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/fcm-blast.php' => $this->app->configPath('fcm-blast.php'),
            ], 'fcm-blast-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'fcm-blast-migrations');
        }
    }

    /**
     * Decode the configured credentials (path or raw JSON) into an array,
     * or null for fake-token mode.
     *
     * @return array<string,mixed>|null
     */
    private function serviceAccount(mixed $credentials): ?array
    {
        if (empty($credentials)) {
            return null;
        }

        if (is_array($credentials)) {
            return $credentials;
        }

        $json = is_string($credentials) && is_file($credentials)
            ? (string) file_get_contents($credentials)
            : (string) $credentials;

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('fcm-blast.credentials is not valid JSON or a readable file path.');
        }

        return $decoded;
    }
}
