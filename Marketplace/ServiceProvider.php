<?php

namespace App\Modules\Commerce\Marketplace;

use App\Modules\Commerce\Marketplace\Console\Commands\EbayAccountSetupCommand;
use App\Modules\Commerce\Marketplace\Console\Commands\EbayMetadataRefreshCommand;
use App\Modules\Commerce\Marketplace\Console\Commands\EbayPoliciesCommand;
use App\Modules\Commerce\Marketplace\Console\Commands\EbayPullCommand;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannelProvider;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannelProvider;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Plugins\Services\CommercePluginDiscoveryService;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketplaceChannelRegistry::class);
        $this->app->singleton(EbayMarketplaceChannelProvider::class);

        $this->app->bind(MarketplaceChannel::class, function (): MarketplaceChannel {
            return $this->app
                ->make(MarketplaceChannelRegistry::class)
                ->channel(EbayConfiguration::CHANNEL);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'commerce-marketplace');

        $commercePlugins = $this->app->make(CommercePluginRegistry::class);
        $this->app->make(CommercePluginDiscoveryService::class)->discoverInto($commercePlugins);

        $this->registerChannelProviders([
            EbayMarketplaceChannelProvider::class,
            ...$commercePlugins->marketplaceChannelProviders(),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EbayMetadataRefreshCommand::class,
                EbayPullCommand::class,
                EbayPoliciesCommand::class,
                EbayAccountSetupCommand::class,
            ]);
        }

        // Register on booted() (not bootstrap/app.php withSchedule): Laravel's
        // withSchedule only attaches when Artisan starts, so the admin Scheduled
        // Tasks page would otherwise omit these while schedule:list still showed them.
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            // Order-pull backstop: webhooks deliver sales in near real time; this
            // incremental, idempotent poll catches anything a webhook missed.
            $schedule->command('commerce:marketplace:ebay:pull --orders')
                ->cron((string) config('commerce-marketplace.order_poll_cron', '*/15 * * * *'))
                ->withoutOverlapping();

            // Cached eBay category rules stay current without a manual refresh:
            // mapping saves refresh their category immediately; this nightly sweep
            // covers everything mapped.
            $schedule->command('commerce:marketplace:ebay:metadata-refresh')
                ->cron((string) config('commerce-marketplace.metadata_refresh_cron', '0 3 * * *'))
                ->withoutOverlapping();
        });
    }

    /**
     * @param  list<class-string<MarketplaceChannelProvider>>  $providers
     */
    private function registerChannelProviders(array $providers): void
    {
        $registry = $this->app->make(MarketplaceChannelRegistry::class);

        foreach ($providers as $providerClass) {
            $this->app
                ->make($providerClass)
                ->registerMarketplaceChannel($registry);
        }
    }
}
