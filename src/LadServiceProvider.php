<?php

namespace Lad;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Lad\Commands\CancelDripCampaignCommand;
use Lad\Commands\DripStatusCommand;
use Lad\Commands\SendDripEmailsCommand;
use Lad\Facades\Lad;
use Lad\Http\Controllers\UnsubscribeController;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Services\LadEngine;
use Lad\Services\NotificationDedupeService;

class LadServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lad.php', 'lad');
        $this->mergeConfigFrom(__DIR__.'/../config/lad.php', 'lifecycle');

        $this->app->singleton(NotificationDedupeService::class, function (): NotificationDedupeService {
            return new NotificationDedupeService;
        });

        $this->app->singleton('lad.engine', function ($app): LadEngine {
            return new LadEngine($app->make(NotificationDedupeService::class));
        });

        $this->app->alias('lad.engine', LadEngine::class);

        $this->registerCompatibilityAliases();
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lad.php' => config_path('lad.php'),
            ], 'lad-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_lad_notification_logs_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_lad_notification_logs_table.php'),
                __DIR__.'/../database/migrations/create_lad_campaign_states_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_create_lad_campaign_states_table.php'),
            ], 'lad-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/lad'),
            ], 'lad-views');

            $this->commands([
                SendDripEmailsCommand::class,
                DripStatusCommand::class,
                CancelDripCampaignCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lad');

        if ((bool) config('lad.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    /**
     * Register the unsubscribe routes.
     */
    protected function registerRoutes(): void
    {
        $prefix = (string) config('lad.routes.prefix', 'lad');
        $middleware = (array) config('lad.routes.middleware', ['web']);

        Route::group([
            'prefix' => $prefix,
            'middleware' => $middleware,
        ], function (): void {
            Route::get('/unsubscribe/{recipient}', [UnsubscribeController::class, 'unsubscribe'])
                ->name('lad.unsubscribe');

            Route::post('/resubscribe/{recipient}', [UnsubscribeController::class, 'resubscribe'])
                ->name('lad.resubscribe');
        });
    }

    /**
     * Register specification-compatible and vendor aliases.
     */
    protected function registerCompatibilityAliases(): void
    {
        $aliases = [
            'Vendor\\Lifecycle\\Facades\\Lifecycle' => Lad::class,
            'Vendor\\Lifecycle\\Campaign' => Campaign::class,
            'Vendor\\Lifecycle\\Step' => Step::class,
            'Vendor\\Lifecycle\\Models\\LifecycleNotificationLog' => LadNotificationLog::class,
            'Vendor\\Lifecycle\\Models\\LifecycleCampaignState' => LadCampaignState::class,
            'Atlas\\Lifecycle\\Facades\\Lifecycle' => Lad::class,
            'Atlas\\Lifecycle\\Campaign' => Campaign::class,
            'Atlas\\Lifecycle\\Step' => Step::class,
            'Atlas\\Lifecycle\\Models\\LifecycleNotificationLog' => LadNotificationLog::class,
            'Atlas\\Lifecycle\\Models\\LifecycleCampaignState' => LadCampaignState::class,
            'Lad\\Facades\\Lifecycle' => Lad::class,
            'Lad\\Facades\\Drip' => Lad::class,
        ];

        foreach ($aliases as $alias => $original) {
            if (! class_exists($alias)) {
                class_alias($original, $alias);
            }
        }
    }
}
