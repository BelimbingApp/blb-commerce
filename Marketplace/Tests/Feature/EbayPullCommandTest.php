<?php

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

class EbayPullCommandTestChannel implements MarketplaceChannel
{
    /** @var array<int, true> */
    public static array $connected = [];

    public static int $pullListingsCalls = 0;

    public static bool $failPull = false;

    public function key(): string
    {
        return EbayConfiguration::CHANNEL;
    }

    public function isConnected(int $companyId): bool
    {
        return isset(self::$connected[$companyId]);
    }

    public function pullListings(int $companyId): MarketplacePullResult
    {
        self::$pullListingsCalls++;

        if (self::$failPull) {
            throw MarketplaceOperationException::requestFailed('ebay', 'listings.inventory_items.pull');
        }

        return new MarketplacePullResult(EbayConfiguration::CHANNEL, 0, 0, 0, 0);
    }

    public function pullOrders(int $companyId): MarketplacePullResult
    {
        return new MarketplacePullResult(EbayConfiguration::CHANNEL, 0, 0, 0, 0);
    }

    public function createListing(Item $item): array
    {
        return [];
    }

    public function reviseListing(Listing $listing): array
    {
        return [];
    }

    public function endListing(Listing $listing): array
    {
        return [];
    }

    public function refreshListingDraft(Item $item): ListingDraft
    {
        return new ListingDraft;
    }
}

test('ebay pull only runs for companies connected to the channel', function (): void {
    EbayPullCommandTestChannel::$pullListingsCalls = 0;
    EbayPullCommandTestChannel::$failPull = false;
    EbayPullCommandTestChannel::$connected = [];

    $connected = Company::factory()->create(['name' => 'Connected Co']);
    Company::factory()->create(['name' => 'Unconnected Co']);
    EbayPullCommandTestChannel::$connected[$connected->id] = true;

    $registry = Mockery::mock(MarketplaceChannelRegistry::class);
    $registry->shouldReceive('channel')
        ->once()
        ->with(EbayConfiguration::CHANNEL)
        ->andReturn(new EbayPullCommandTestChannel);

    $this->app->instance(MarketplaceChannelRegistry::class, $registry);

    $this->artisan('commerce:marketplace:ebay:pull', ['--orders' => true])
        ->doesntExpectOutputToContain('skipped')
        ->assertSuccessful();

    expect(EbayPullCommandTestChannel::$pullListingsCalls)->toBe(1);
});

test('ebay pull reports nothing when no company is connected', function (): void {
    EbayPullCommandTestChannel::$pullListingsCalls = 0;
    EbayPullCommandTestChannel::$failPull = false;
    EbayPullCommandTestChannel::$connected = [];

    Company::factory()->create(['name' => 'Unconnected Co']);

    $registry = Mockery::mock(MarketplaceChannelRegistry::class);
    $registry->shouldReceive('channel')->andReturn(new EbayPullCommandTestChannel);

    $this->app->instance(MarketplaceChannelRegistry::class, $registry);

    $this->artisan('commerce:marketplace:ebay:pull')
        ->expectsOutputToContain('No companies connected to eBay')
        ->assertSuccessful();

    expect(EbayPullCommandTestChannel::$pullListingsCalls)->toBe(0);
});

test('ebay pull still fails when a connected company errors', function (): void {
    EbayPullCommandTestChannel::$pullListingsCalls = 0;
    EbayPullCommandTestChannel::$failPull = true;
    EbayPullCommandTestChannel::$connected = [];

    $connected = Company::factory()->create(['name' => 'Connected Co']);
    EbayPullCommandTestChannel::$connected[$connected->id] = true;

    $registry = Mockery::mock(MarketplaceChannelRegistry::class);
    $registry->shouldReceive('channel')->andReturn(new EbayPullCommandTestChannel);

    $this->app->instance(MarketplaceChannelRegistry::class, $registry);

    $this->artisan('commerce:marketplace:ebay:pull')
        ->assertFailed();

    expect(EbayPullCommandTestChannel::$pullListingsCalls)->toBe(1);
});
