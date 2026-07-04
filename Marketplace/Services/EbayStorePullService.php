<?php

namespace App\Modules\Commerce\Marketplace\Services;

use App\Modules\Commerce\Marketplace\DTO\EbayStorePullResult;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannel;
use Throwable;

/**
 * Full eBay store pull: Inventory listings, orders, and the Trading-API active-set
 * mirror. Too many outbound calls to run inside a Livewire HTTP request.
 */
class EbayStorePullService
{
    public function __construct(
        private readonly EbayMarketplaceChannel $channel,
    ) {}

    public function pull(int $companyId): EbayStorePullResult
    {
        $listings = $this->channel->pullListings($companyId);
        $orders = $this->channel->pullOrders($companyId);

        try {
            $reconcile = $this->channel->reconcileSellerListings($companyId);
        } catch (Throwable $exception) {
            return new EbayStorePullResult(
                listings: $listings,
                orders: $orders,
                reconcileError: $exception->getMessage(),
            );
        }

        return new EbayStorePullResult(
            listings: $listings,
            orders: $orders,
            reconcile: $reconcile,
        );
    }
}
