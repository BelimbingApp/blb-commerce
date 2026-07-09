<?php

use App\Modules\Commerce\Marketplace\Ebay\Http\Controllers\EbayAccountDeletionController;
use App\Modules\Commerce\Marketplace\Ebay\Http\Controllers\EbayOAuthCallbackController;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Index;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated: eBay's servers call this through the tunnel.
// CSRF is exempted for webhooks/* in bootstrap/app.php. Rate-limited because it
// is publicly reachable: eBay's real deletion traffic is low-volume, so a
// generous per-minute cap absorbs its retries while blunting spam/DoS from
// anyone else who finds the URL.
Route::match(['get', 'post'], 'webhooks/ebay/account-deletion', EbayAccountDeletionController::class)
    ->middleware('throttle:120,1')
    ->name('commerce.marketplace.ebay.webhooks.account-deletion');

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/marketplace/ebay', Index::class)
        ->middleware('authz:commerce.marketplace.list')
        ->name('commerce.marketplace.ebay.index');

    Route::get('commerce/marketplace/ebay/settings', Settings::class)
        ->middleware('authz:commerce.marketplace.manage')
        ->name('commerce.marketplace.ebay.settings');

    Route::get('commerce/marketplace/ebay/oauth/callback', EbayOAuthCallbackController::class)
        ->middleware('authz:commerce.marketplace.manage')
        ->name('commerce.marketplace.ebay.oauth.callback');
});
