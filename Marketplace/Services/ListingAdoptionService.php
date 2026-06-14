<?php

namespace App\Modules\Commerce\Marketplace\Services;

use App\Base\Media\Models\MediaAsset;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannel;
use App\Modules\Commerce\Marketplace\Models\Listing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turn an imported marketplace {@see Listing} into a linked inventory {@see Item}
 * (the "pull → populate inventory" Day-1 flow). Channel-agnostic at its core: the
 * item is built from a normalized detail array, enriched per-listing from eBay's
 * Trading GetItem. Read-only against the marketplace — adoption never writes to
 * the live listing; making legacy listings eBay-revisable (relist) is deferred.
 */
class ListingAdoptionService
{
    public function __construct(
        private readonly EbayMarketplaceChannel $channel,
    ) {}

    /**
     * Adopt one listing into a linked inventory item. Idempotent: an already
     * linked listing returns its existing item without a second eBay call.
     */
    public function adopt(Listing $listing): Item
    {
        if ($listing->item_id !== null) {
            return $listing->item ?? Item::query()->findOrFail($listing->item_id);
        }

        $detail = $this->channel->fetchListingDetail(
            $listing->company_id,
            (string) $listing->external_listing_id,
        );

        return $this->createItemFromDetail($listing, $detail);
    }

    /**
     * Build and link the item from an already-fetched, normalized detail array.
     * Kept separate from {@see adopt()} so the marketplace fetch and the local
     * population can be tested independently.
     *
     * @param  array<string, mixed>  $detail
     */
    public function createItemFromDetail(Listing $listing, array $detail): Item
    {
        return DB::transaction(function () use ($listing, $detail): Item {
            // Lock the row and re-check inside the transaction so a concurrent
            // adoption (row-level Adopt racing the Adopt-all job) cannot create
            // duplicate items for the same listing.
            $locked = Listing::query()->whereKey($listing->id)->lockForUpdate()->first();

            if ($locked !== null && $locked->item_id !== null) {
                return $locked->item ?? Item::query()->findOrFail($locked->item_id);
            }

            $listing = $locked ?? $listing;
            $companyId = $listing->company_id;

            $sku = $this->firstNonEmpty([$detail['sku'] ?? null, $listing->external_sku]);

            // A SKU that already maps to a local item means "link", not "duplicate".
            if ($sku !== null) {
                $existing = Item::query()
                    ->where('company_id', $companyId)
                    ->where('sku', strtoupper($sku))
                    ->first();

                if ($existing !== null) {
                    $this->linkListing($listing, $existing, $detail);

                    return $existing;
                }
            }

            $title = $this->firstNonEmpty([$detail['title'] ?? null, $listing->title])
                ?? (string) __('Imported eBay listing :id', ['id' => $listing->external_listing_id]);
            $currency = $this->firstNonEmpty([$detail['currency_code'] ?? null, $listing->currency_code]) ?? 'USD';
            $price = $this->firstInt([$detail['price_amount'] ?? null, $listing->price_amount]);
            $quantity = $this->firstInt([
                $detail['quantity'] ?? null,
                data_get($listing->raw_payload, 'trading_item.quantity'),
            ]) ?? 0;
            $description = $this->firstNonEmpty([$detail['description'] ?? null, $listing->marketplaceDescriptionBody()]);

            [$templateId, $categoryId] = $this->guessTemplate($companyId, $this->stringOrNull($detail['category_id'] ?? null));

            $item = Item::create([
                'company_id' => $companyId,
                'product_template_id' => $templateId,
                'category_id' => $categoryId,
                'sku' => strtoupper($sku ?? $this->generateSku($listing)),
                'status' => Item::STATUS_LISTED,
                'title' => $title,
                'description' => $description,
                'quantity_on_hand' => max(0, $quantity),
                'currency_code' => strtoupper($currency),
                'target_price_amount' => $price,
            ]);

            $this->importPhotos($item, $this->arrayOf($detail['photo_urls'] ?? []));
            $this->importFitment($item, $listing, $detail);
            $this->linkListing($listing, $item, $detail);

            return $item;
        });
    }

    private function linkListing(Listing $listing, Item $item, array $detail): void
    {
        // Legacy (Seller-Hub) listings can be linked for local management but not
        // revised via the Inventory API — keep them imported. Only Inventory-API
        // listings graduate to Belimbing-managed.
        $managementState = $listing->adoptionState() === Listing::ADOPTION_INVENTORY_API_ADOPTABLE
            ? Listing::MANAGEMENT_BELIMBING_MANAGED
            : $listing->management_state;

        $listing->forceFill([
            'item_id' => $item->id,
            'management_state' => $managementState,
            'last_synced_at' => Carbon::now(),
            'raw_payload' => [
                ...($listing->raw_payload ?? []),
                'adopted_detail' => $detail,
            ],
        ])->save();
    }

    /**
     * @param  list<string>  $urls
     */
    private function importPhotos(Item $item, array $urls): void
    {
        $sort = 0;

        foreach ($urls as $url) {
            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

            $asset = MediaAsset::query()->firstOrCreate(
                [
                    'disk' => MediaAsset::DISK_EXTERNAL,
                    'storage_key' => 'ebay-adopt/'.$item->id.'/'.sha1($url),
                ],
                [
                    'kind' => 'external_url',
                    'metadata' => ['public_url' => $url, 'source' => 'ebay_adoption'],
                ],
            );

            ItemPhoto::query()->firstOrCreate(
                ['item_id' => $item->id, 'media_asset_id' => $asset->id],
                ['sort_order' => $sort],
            );

            $sort++;
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function importFitment(Item $item, Listing $listing, array $detail): void
    {
        $compatibility = $this->arrayOf($detail['compatibility'] ?? []);

        foreach ($compatibility as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            ItemFitment::create([
                'company_id' => $item->company_id,
                'item_id' => $item->id,
                'channel' => $listing->channel,
                'marketplace_id' => $listing->marketplace_id,
                'category_id' => $this->stringOrNull($detail['category_id'] ?? null),
                'is_universal' => false,
                'compatibility_properties' => is_array($entry['properties'] ?? null) ? $entry['properties'] : null,
                'display_year' => $this->stringOrNull($entry['year'] ?? null),
                'display_make' => $this->stringOrNull($entry['make'] ?? null),
                'display_model' => $this->stringOrNull($entry['model'] ?? null),
                'display_trim' => $this->stringOrNull($entry['trim'] ?? null),
                'display_engine' => $this->stringOrNull($entry['engine'] ?? null),
                'source' => ItemFitment::SOURCE_IMPORTED,
                'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
            ]);
        }
    }

    /**
     * Reverse-lookup a Ham product template by the eBay category it maps to
     * (`metadata.marketplace.ebay.category_id`). Returns [templateId, categoryId]
     * or [null, null] so the operator can map it later.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function guessTemplate(int $companyId, ?string $ebayCategoryId): array
    {
        if ($ebayCategoryId === null) {
            return [null, null];
        }

        $template = ProductTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('metadata->marketplace->ebay->category_id', $ebayCategoryId)
            ->first();

        return $template !== null ? [$template->id, $template->category_id] : [null, null];
    }

    private function generateSku(Listing $listing): string
    {
        return 'EBAY-'.$listing->external_listing_id;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<mixed>
     */
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
