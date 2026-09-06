<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lad\Facades\Drip;
use Lad\Facades\Lad;
use Lad\Facades\Lifecycle;
use Lad\LadServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Lad::clearCampaigns();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LadServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Lad' => Lad::class,
            'Drip' => Drip::class,
            'Lifecycle' => Lifecycle::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:yk3RnvA+S4v9+K23v3x9s7Jq1xY8/J5n7/6d4A9wZ8g=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('lad.recipient_model', User::class);
        $app['config']->set('lad.global_cooldown_hours', 48);
        $app['config']->set('lad.enrollment_started_at', null);
        $app['config']->set('lad.enabled', true);
    }
}
