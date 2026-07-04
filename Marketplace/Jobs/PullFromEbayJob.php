<?php

namespace App\Modules\Commerce\Marketplace\Jobs;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Services\EbayStorePullService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pull the full eBay store into Belimbing off the HTTP request. Inventory API
 * listing import, order ingest, and the Trading-API mirror can fan out into
 * hundreds of outbound calls — too slow for Cloudflare's origin timeout.
 */
class PullFromEbayJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public readonly int $companyId,
    ) {}

    public function handle(EbayStorePullService $pulls, SettingsService $settings): void
    {
        $scope = Scope::company($this->companyId);

        try {
            $result = $pulls->pull($this->companyId);
            $this->rememberPullNotice($settings, $scope, 'success', $result->notificationMessage());
        } catch (Throwable $exception) {
            blb_log_var([
                'company_id' => $this->companyId,
                'error' => $exception->getMessage(),
            ], 'ebay-pull.log', [], 'error');

            $this->rememberPullNotice(
                $settings,
                $scope,
                'error',
                $exception->getMessage(),
            );

            throw $exception;
        }
    }

    private function rememberPullNotice(
        SettingsService $settings,
        Scope $scope,
        string $status,
        string $message,
    ): void {
        $settings->set('commerce.marketplace.ebay.last_pull_status', $status, $scope);
        $settings->set('commerce.marketplace.ebay.last_pull_message', $message, $scope);
        $settings->set(
            'commerce.marketplace.ebay.last_pull_at',
            Carbon::now()->utc()->toIso8601String(),
            $scope,
        );
    }
}
