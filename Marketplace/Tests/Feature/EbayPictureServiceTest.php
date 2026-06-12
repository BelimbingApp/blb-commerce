<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Media\Models\MediaAsset;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayPictureService;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function createPictureServiceItem(int $companyId, ?string $publicUrl = null): Item
{
    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        Scope::company($companyId),
        [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    Storage::fake('local');
    Storage::disk('local')->put('testing/hose.jpg', 'fake-jpeg-bytes');

    $item = Item::factory()->create([
        'company_id' => $companyId,
        'sku' => 'HOSE-SET-001',
    ]);

    $asset = MediaAsset::query()->create([
        'disk' => 'local',
        'storage_key' => 'testing/hose.jpg',
        'original_filename' => 'hose.jpg',
        'mime_type' => 'image/jpeg',
        'kind' => MediaAsset::KIND_ORIGINAL,
        'metadata' => $publicUrl !== null ? ['public_url' => $publicUrl] : null,
    ]);

    ItemPhoto::query()->create([
        'item_id' => $item->id,
        'media_asset_id' => $asset->id,
        'sort_order' => 1,
    ]);

    return $item->fresh();
}

function epsResponse(string $fullUrl): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <UploadSiteHostedPicturesResponse xmlns="urn:ebay:apis:eBLBaseComponents">
      <Ack>Success</Ack>
      <Version>1267</Version>
      <SiteHostedPictureDetails>
        <FullURL>{$fullUrl}</FullURL>
      </SiteHostedPictureDetails>
    </UploadSiteHostedPicturesResponse>
    XML;
}

test('local photos are uploaded to eBay Picture Services and the hosted URL is stored', function (): void {
    $user = createAdminUser();
    $item = createPictureServiceItem($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/ws/api.dll' => Http::response(
            epsResponse('http://i.sandbox.ebayimg.com/00/s/ABC/$_1.JPG?set_id=880000500F'),
        ),
    ]);

    $urls = app(EbayPictureService::class)->ensureHostedPhotos($item);

    // http:// EPS URLs are normalized to https:// because listings require it.
    expect($urls)->toBe(['https://i.sandbox.ebayimg.com/00/s/ABC/$_1.JPG?set_id=880000500F']);

    $metadata = $item->photos()->first()->mediaAsset->fresh()->metadata;
    expect($metadata['public_url'])->toBe('https://i.sandbox.ebayimg.com/00/s/ABC/$_1.JPG?set_id=880000500F')
        ->and($metadata['public_url_source'])->toBe('ebay_eps');

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('X-EBAY-API-CALL-NAME', 'UploadSiteHostedPictures')
            && $request->hasHeader('X-EBAY-API-IAF-TOKEN', 'access-token')
            && str_contains((string) $request->body(), 'UploadSiteHostedPicturesRequest')
            && str_contains((string) $request->body(), 'fake-jpeg-bytes');
    });
});

test('photos that already have a hosted URL are reused without re-uploading', function (): void {
    $user = createAdminUser();
    $item = createPictureServiceItem($user->company_id, 'https://i.ebayimg.com/already/hosted.jpg');

    Http::fake();

    $urls = app(EbayPictureService::class)->ensureHostedPhotos($item);

    expect($urls)->toBe(['https://i.ebayimg.com/already/hosted.jpg']);
    Http::assertNothingSent();
});

test('an eBay picture upload rejection surfaces as a marketplace exception', function (): void {
    $user = createAdminUser();
    $item = createPictureServiceItem($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/ws/api.dll' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <UploadSiteHostedPicturesResponse xmlns="urn:ebay:apis:eBLBaseComponents">
          <Ack>Failure</Ack>
          <Errors>
            <ShortMessage>Picture rejected.</ShortMessage>
            <LongMessage>The picture format is not supported.</LongMessage>
          </Errors>
        </UploadSiteHostedPicturesResponse>
        XML),
    ]);

    app(EbayPictureService::class)->ensureHostedPhotos($item);
})->throws(MarketplaceOperationException::class, 'The picture format is not supported.');
