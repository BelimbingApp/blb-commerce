<?php

namespace App\Modules\Commerce\Marketplace\Contracts;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;

interface MarketplaceChannel
{
    public function key(): string;

    /**
     * Whether this company has a usable account for the channel (OAuth token,
     * API credentials, etc.). Multi-tenant schedulers should only pull for
     * connected companies — a Shopee-only tenant must not fail an eBay tick.
     */
    public function isConnected(int $companyId): bool;

    public function pullListings(int $companyId): MarketplacePullResult;

    public function pullOrders(int $companyId): MarketplacePullResult;

    /**
     * @return array<string, mixed>
     */
    public function createListing(Item $item): array;

    /**
     * @return array<string, mixed>
     */
    public function reviseListing(Listing $listing): array;

    /**
     * @return array<string, mixed>
     */
    public function endListing(Listing $listing): array;

    /**
     * Recompute the item's readiness draft from already-synced local state
     * (settings, cached marketplace metadata, stored tokens). Must not call
     * the remote marketplace: the item page re-runs this on load and after
     * every relevant edit so the readiness verdict is never stale.
     */
    public function refreshListingDraft(Item $item): ListingDraft;
}
