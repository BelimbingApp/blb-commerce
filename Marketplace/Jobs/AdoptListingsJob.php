<?php

namespace App\Modules\Commerce\Marketplace\Jobs;

use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\ListingAdoptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Bulk-adopt imported listings into inventory items. Each listing needs its own
 * eBay GetItem call, so this runs off the request. One listing failing (e.g. an
 * eBay hiccup) is logged and skipped — the rest still adopt. Idempotent: already
 * linked listings are not re-adopted.
 */
class AdoptListingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<int>  $listingIds
     */
    public function __construct(
        public readonly int $companyId,
        public readonly array $listingIds,
    ) {}

    public function handle(ListingAdoptionService $adoptions): void
    {
        // Only ids (light) and only unlinked eBay listings — defensive against a
        // batch that includes non-eBay or already-linked ids, and avoids loading
        // hundreds of full models at once.
        $listingIds = Listing::query()
            ->where('company_id', $this->companyId)
            ->whereIn('id', $this->listingIds)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->whereNull('item_id')
            ->pluck('id');

        foreach ($listingIds as $listingId) {
            $listing = Listing::query()
                ->where('company_id', $this->companyId)
                ->where('channel', EbayConfiguration::CHANNEL)
                ->whereNull('item_id')
                ->find($listingId);

            if ($listing === null) {
                continue;
            }

            try {
                $adoptions->adopt($listing);
            } catch (Throwable $exception) {
                blb_log_var([
                    'listing_id' => $listing->id,
                    'external_listing_id' => $listing->external_listing_id,
                    'error' => $exception->getMessage(),
                ], 'listing-adoption.log', [], 'error');
            }
        }
    }
}
