<?php

namespace App\Providers;

use App\Contracts\PriceProvider;
use App\Redis\AlertIndex;
use App\Services\AlertClaimer;
use App\Services\PriceIngestor;
use App\Services\Prices\PriceProviderManager;
use App\Support\PriceCache;
use App\Support\RabbitPublisher;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PriceProviderManager::class);

        $this->app->bind(
            PriceProvider::class,
            fn ($app) => $app->make(PriceProviderManager::class)->driver(),
        );

        $this->app->singleton(AlertIndex::class, fn () => new AlertIndex(Redis::connection()));

        $this->app->singleton(PriceCache::class, fn ($app) => new PriceCache($app->make('cache')->store()));

        $this->app->singleton(PriceIngestor::class, fn ($app) => new PriceIngestor(
            $app->make(PriceProvider::class),
            $app->make(PriceCache::class),
        ));

        $this->app->singleton(AlertClaimer::class, fn ($app) => new AlertClaimer(
            $app->make(AlertIndex::class),
        ));

        $this->app->singleton(RabbitPublisher::class, fn () => new RabbitPublisher(
            (string) config('queue.connections.rabbitmq.hosts.0.host'),
            (int) config('queue.connections.rabbitmq.hosts.0.port'),
            (string) config('queue.connections.rabbitmq.hosts.0.user'),
            (string) config('queue.connections.rabbitmq.hosts.0.password'),
            (string) config('queue.connections.rabbitmq.hosts.0.vhost'),
        ));
    }

    public function boot(): void {}
}
