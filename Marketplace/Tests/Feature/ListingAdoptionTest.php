<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Media\Models\MediaAsset;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Jobs\AdoptListingsJob;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Index as MarketplaceIndex;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\ListingAdoptionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('adoption creates a linked inventory item populated from listing detail', function (): void {
    $user = createAdminUser();
    $companyId = $user->company_id;

    $category = Category::factory()->create(['company_id' => $companyId]);
    $template = ProductTemplate::factory()->create([
        'company_id' => $companyId,
        'category_id' => $category->id,
        'metadata' => ['marketplace' => ['ebay' => ['category_id' => '33710']]],
    ]);

    $listing = adoptableListing($companyId);

    $item = app(ListingAdoptionService::class)->createItemFromDetail($listing, ebayAdoptionDetail());

    expect($item->company_id)->toBe($companyId)
        ->and($item->title)->toBe('Used OEM headlight assembly')
        ->and($item->status)->toBe(Item::STATUS_LISTED)
        ->and($item->sku)->toBe('EBAY-110589612524')
        ->and($item->target_price_amount)->toBe(12000)
        ->and($item->currency_code)->toBe('USD')
        ->and($item->quantity_on_hand)->toBe(2)
        ->and($item->description)->toBe('Genuine OEM headlight, tested.')
        ->and($item->product_template_id)->toBe($template->id)
        ->and($item->category_id)->toBe($category->id);

    $listing->refresh();
    expect($listing->item_id)->toBe($item->id);

    $item->load(['photos.mediaAsset', 'fitments']);
    expect($item->photos)->toHaveCount(2);
    $asset = $item->photos->first()->mediaAsset;
    expect($asset->disk)->toBe(MediaAsset::DISK_EXTERNAL)
        ->and($asset->displayUrl())->toBe('https://i.ebayimg.com/abc.jpg');

    expect($item->fitments)->toHaveCount(1);
    $fitment = $item->fitments->first();
    expect($fitment->source)->toBe('imported')
        ->and($fitment->confidence)->toBe('seller_confirmed')
        ->and($fitment->display_make)->toBe('Honda')
        ->and($fitment->display_model)->toBe('Civic');
});

test('adoption uses the listing SKU when present and generates one when absent', function (): void {
    $user = createAdminUser();

    $withSku = adoptableListing($user->company_id, ['external_listing_id' => '111', 'external_sku' => 'ham-keep-1']);
    $item = app(ListingAdoptionService::class)->createItemFromDetail($withSku, ebayAdoptionDetail(['sku' => null]));
    expect($item->sku)->toBe('HAM-KEEP-1');

    $noSku = adoptableListing($user->company_id, ['external_listing_id' => '222', 'external_sku' => null]);
    $generated = app(ListingAdoptionService::class)->createItemFromDetail($noSku, ebayAdoptionDetail(['sku' => null]));
    expect($generated->sku)->toBe('EBAY-222');
});

test('adoption links to an existing item when the SKU already matches instead of duplicating', function (): void {
    $user = createAdminUser();
    $companyId = $user->company_id;

    $existing = Item::factory()->create(['company_id' => $companyId, 'sku' => 'HAM-MATCH']);
    $listing = adoptableListing($companyId, ['external_sku' => 'ham-match']);

    $before = Item::query()->where('company_id', $companyId)->count();
    $item = app(ListingAdoptionService::class)->createItemFromDetail($listing, ebayAdoptionDetail(['sku' => 'ham-match']));

    expect($item->id)->toBe($existing->id)
        ->and(Item::query()->where('company_id', $companyId)->count())->toBe($before)
        ->and($listing->refresh()->item_id)->toBe($existing->id);
});

test('adopting an already linked listing is a no-op', function (): void {
    $user = createAdminUser();
    $existing = Item::factory()->create(['company_id' => $user->company_id]);
    $listing = adoptableListing($user->company_id, ['item_id' => $existing->id]);

    Http::fake();
    $item = app(ListingAdoptionService::class)->adopt($listing);

    expect($item->id)->toBe($existing->id);
    Http::assertNothingSent();
});

test('legacy listings stay imported while inventory-api listings become belimbing-managed', function (): void {
    $user = createAdminUser();
    $companyId = $user->company_id;

    $legacy = adoptableListing($companyId, ['external_listing_id' => '900', 'external_offer_id' => null]);
    app(ListingAdoptionService::class)->createItemFromDetail($legacy, ebayAdoptionDetail());
    expect($legacy->refresh()->management_state)->toBe(Listing::MANAGEMENT_IMPORTED);

    $inventoryApi = adoptableListing($companyId, [
        'external_listing_id' => '901',
        'external_offer_id' => 'OFFER-901',
        'raw_payload' => ['inventory_item' => ['sku' => 'INV-901']],
    ]);
    app(ListingAdoptionService::class)->createItemFromDetail($inventoryApi, ebayAdoptionDetail());
    expect($inventoryApi->refresh()->management_state)->toBe(Listing::MANAGEMENT_BELIMBING_MANAGED);
});

test('adopt fetches eBay GetItem and populates the item end to end', function (): void {
    $user = createAdminUser();
    configureEbayForAdoption($user->company_id);
    Http::fake(['https://api.sandbox.ebay.com/ws/api.dll' => Http::response(ebayGetItemXml(), 200, ['Content-Type' => 'text/xml'])]);

    $listing = adoptableListing($user->company_id, ['external_sku' => null]);
    $item = app(ListingAdoptionService::class)->adopt($listing);

    expect($item->quantity_on_hand)->toBe(2) // 3 listed - 1 sold
        ->and($item->photos)->toHaveCount(2)
        ->and($item->fitments)->toHaveCount(1);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ws/api.dll')
        && str_contains((string) $request->body(), '<GetItemRequest'));
});

test('the eBay index adopt action links a listing to a new item', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    configureEbayForAdoption($user->company_id);
    Http::fake(['https://api.sandbox.ebay.com/ws/api.dll' => Http::response(ebayGetItemXml(), 200, ['Content-Type' => 'text/xml'])]);

    $listing = adoptableListing($user->company_id);

    Livewire::test(MarketplaceIndex::class)
        ->call('adoptListing', $listing->id)
        ->assertHasNoErrors();

    expect($listing->refresh()->item_id)->not->toBeNull();
});

test('the bulk adoption job adopts unlinked listings and skips linked ones', function (): void {
    $user = createAdminUser();
    $companyId = $user->company_id;
    configureEbayForAdoption($companyId);
    Http::fake(['https://api.sandbox.ebay.com/ws/api.dll' => Http::response(ebayGetItemXml(), 200, ['Content-Type' => 'text/xml'])]);

    $one = adoptableListing($companyId, ['external_listing_id' => '501']);
    $two = adoptableListing($companyId, ['external_listing_id' => '502']);
    $alreadyLinked = adoptableListing($companyId, [
        'external_listing_id' => '503',
        'item_id' => Item::factory()->create(['company_id' => $companyId])->id,
    ]);

    (new AdoptListingsJob($companyId, [$one->id, $two->id, $alreadyLinked->id]))
        ->handle(app(ListingAdoptionService::class));

    expect($one->refresh()->item_id)->not->toBeNull()
        ->and($two->refresh()->item_id)->not->toBeNull();
});

test('adopt all unlinked dispatches a job for the unlinked active listings only', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    Bus::fake();

    $unlinkedA = adoptableListing($user->company_id, ['external_listing_id' => '601']);
    $unlinkedB = adoptableListing($user->company_id, ['external_listing_id' => '602']);
    adoptableListing($user->company_id, [
        'external_listing_id' => '603',
        'item_id' => Item::factory()->create(['company_id' => $user->company_id])->id,
    ]);

    Livewire::test(MarketplaceIndex::class)->call('adoptAllUnlinked');

    Bus::assertDispatched(AdoptListingsJob::class, function (AdoptListingsJob $job) use ($unlinkedA, $unlinkedB): bool {
        $ids = $job->listingIds;
        sort($ids);

        return $ids === collect([$unlinkedA->id, $unlinkedB->id])->sort()->values()->all();
    });
});

function adoptableListing(int $companyId, array $overrides = []): Listing
{
    return Listing::factory()->create(array_merge([
        'company_id' => $companyId,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => '110589612524',
        'external_offer_id' => null,
        'external_sku' => null,
        'item_id' => null,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Used OEM headlight assembly',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'price_amount' => 12000,
        'currency_code' => 'USD',
        'ended_at' => null,
        'raw_payload' => ['trading_item' => ['quantity' => 2]],
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ebayAdoptionDetail(array $overrides = []): array
{
    return array_merge([
        'item_id' => '110589612524',
        'title' => 'Used OEM headlight assembly',
        'sku' => null,
        'price_amount' => 12000,
        'currency_code' => 'USD',
        'quantity' => 2,
        'description' => 'Genuine OEM headlight, tested.',
        'condition_id' => '3000',
        'condition_display' => 'Used',
        'category_id' => '33710',
        'photo_urls' => ['https://i.ebayimg.com/abc.jpg', 'https://i.ebayimg.com/def.jpg'],
        'specifics' => ['Brand' => 'Honda'],
        'compatibility' => [
            ['year' => '2008', 'make' => 'Honda', 'model' => 'Civic', 'trim' => null, 'engine' => null, 'properties' => ['Year' => '2008', 'Make' => 'Honda', 'Model' => 'Civic']],
        ],
    ], $overrides);
}

function configureEbayForAdoption(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('commerce.marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('commerce.marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('commerce.marketplace.ebay.client_id', 'client-123', $scope);
    $settings->set('commerce.marketplace.ebay.client_secret', 'secret-456', $scope, encrypted: true);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        ['access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600],
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );
}

function ebayGetItemXml(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents">
      <Ack>Success</Ack>
      <Item>
        <ItemID>110589612524</ItemID>
        <Title>Used OEM headlight assembly</Title>
        <SKU></SKU>
        <Quantity>3</Quantity>
        <SellingStatus><CurrentPrice currencyID="USD">120.00</CurrentPrice><QuantitySold>1</QuantitySold></SellingStatus>
        <PrimaryCategory><CategoryID>33710</CategoryID></PrimaryCategory>
        <ConditionID>3000</ConditionID>
        <ConditionDisplayName>Used</ConditionDisplayName>
        <Description>Genuine OEM headlight, tested.</Description>
        <PictureDetails>
          <PictureURL>https://i.ebayimg.com/abc.jpg</PictureURL>
          <PictureURL>https://i.ebayimg.com/def.jpg</PictureURL>
        </PictureDetails>
        <ItemSpecifics>
          <NameValueList><Name>Brand</Name><Value>Honda</Value></NameValueList>
        </ItemSpecifics>
        <ItemCompatibilityList>
          <Compatibility>
            <NameValueList><Name>Year</Name><Value>2008</Value></NameValueList>
            <NameValueList><Name>Make</Name><Value>Honda</Value></NameValueList>
            <NameValueList><Name>Model</Name><Value>Civic</Value></NameValueList>
          </Compatibility>
        </ItemCompatibilityList>
      </Item>
    </GetItemResponse>
    XML;
}
