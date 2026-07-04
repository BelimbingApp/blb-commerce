<?php

namespace App\Modules\Commerce\Marketplace\DTO;

readonly class EbayStorePullResult
{
    public function __construct(
        public MarketplacePullResult $listings,
        public MarketplacePullResult $orders,
        public ?MarketplaceReconcileResult $reconcile = null,
        public ?string $reconcileError = null,
    ) {}

    public function notificationMessage(): string
    {
        $reconcileNote = '';

        if ($this->reconcile instanceof MarketplaceReconcileResult) {
            $reconcileNote = ' '.__('Mirrored :active live listing(s) (:created new, :ended ended).', [
                'active' => $this->reconcile->active,
                'created' => $this->reconcile->created,
                'ended' => $this->reconcile->ended,
            ]);

            if (! $this->reconcile->complete) {
                $reconcileNote .= ' '.__('Partial set — older listings were not retired.');
            }
        } elseif (is_string($this->reconcileError) && $this->reconcileError !== '') {
            $reconcileNote = ' '.__('Listing mirror skipped: :message', ['message' => $this->reconcileError]);
        }

        return __(
            'Pulled from eBay — :listingsFetched listings (:listingsCreated new, :listingsUpdated updated) and :ordersFetched orders (:ordersCreated new).',
            [
                'listingsFetched' => $this->listings->fetched,
                'listingsCreated' => $this->listings->created,
                'listingsUpdated' => $this->listings->updated,
                'ordersFetched' => $this->orders->fetched,
                'ordersCreated' => $this->orders->created,
            ],
        ).$reconcileNote;
    }
}
