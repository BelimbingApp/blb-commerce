<?php

namespace App\Modules\Commerce\Marketplace\Console\Commands;

use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'commerce:marketplace:ebay:pull')]
class EbayPullCommand extends Command
{
    protected $description = 'Pull eBay marketplace listings and orders for connected companies';

    protected $signature = 'commerce:marketplace:ebay:pull
        {--company-id= : Company ID to pull for. Omit to pull every company connected to eBay.}
        {--orders : Also pull and materialize eBay orders into Commerce Sales.}';

    public function handle(MarketplaceChannelRegistry $channels): int
    {
        $channel = $channels->channel(EbayConfiguration::CHANNEL);
        $companyIds = $this->companyIds($channel);

        if ($companyIds === []) {
            $this->components->info('No companies connected to eBay — nothing to pull.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($companyIds as $companyId) {
            try {
                $result = $channel->pullListings($companyId);
                $this->components->info("Company {$companyId}: pulled {$result->fetched} eBay listing(s), linked {$result->linked} by SKU.");

                if ($this->option('orders')) {
                    $orders = $channel->pullOrders($companyId);
                    $this->components->info("Company {$companyId}: pulled {$orders->fetched} eBay order(s), linked {$orders->linked} line(s) to inventory.");

                    foreach ($orders->warnings as $warning) {
                        $this->components->warn($warning);
                    }
                }
            } catch (Throwable $exception) {
                $exitCode = self::FAILURE;
                $this->components->error("Company {$companyId}: {$exception->getMessage()}");
            }
        }

        return $exitCode;
    }

    /**
     * @return list<int>
     */
    private function companyIds(MarketplaceChannel $channel): array
    {
        $companyId = $this->option('company-id');

        if ($companyId !== null && $companyId !== '') {
            $id = (int) $companyId;

            if (! $channel->isConnected($id)) {
                $this->components->warn("Company {$id}: skipped — eBay is not connected.");

                return [];
            }

            return [$id];
        }

        return Company::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $channel->isConnected($id))
            ->values()
            ->all();
    }
}
