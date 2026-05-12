<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGoogleDrive;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Google Drive connector package.
 *
 * Merges the Google Drive provider block into the host's
 * `connectors.php` config tree (under `providers.google-drive`).
 * Publishes both the config fragment + the brand asset for hosts that
 * want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class GoogleDriveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/google-drive.php',
            'connectors.providers.google-drive',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/google-drive.php' => config_path('connectors-google-drive.php'),
            ], 'connector-google-drive-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-google-drive-assets');
        }
    }
}
