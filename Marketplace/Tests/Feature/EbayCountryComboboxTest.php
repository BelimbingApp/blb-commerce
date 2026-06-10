<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('eBay Step 2 country selector still stores a 2-letter ISO via the combobox', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    app(SettingsService::class)->set('marketplace.ebay.environment', 'sandbox', $scope);
    app(SettingsService::class)->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);

    Http::fake(function ($request) {
        return match (true) {
            $request->method() === 'POST' && str_contains($request->url(), '/sell/inventory/v1/location/') => Http::response([], 204),
            str_contains($request->url(), '/sell/inventory/v1/location') => Http::response(['locations' => []], 200),
            default => Http::response([], 200),
        };
    });

    $this->actingAs($user);

    // Default is 'US'; the dropdown stores the ISO code, not a country name.
    // A 2-letter ISO passes validation (size:2) and reaches eBay; a country
    // name would fail validation, proving the binding stores the code.
    Livewire::test(EbaySettings::class)
        ->assertSet('newLocationCountry', 'US')
        ->set('newLocationKey', 'california_shop')
        ->set('newLocationCountry', 'US')
        ->set('newLocationState', 'CA')
        ->set('newLocationCity', 'Los Angeles')
        ->set('newLocationPostal', '90001')
        ->call('createMerchantLocation')
        ->assertHasNoErrors();

    // A non-2-char value (e.g. a country name) is rejected by validation.
    Livewire::test(EbaySettings::class)
        ->set('newLocationKey', 'california_shop')
        ->set('newLocationCity', 'Los Angeles')
        ->set('newLocationState', 'CA')
        ->set('newLocationPostal', '90001')
        ->set('newLocationCountry', 'United States')
        ->call('createMerchantLocation')
        ->assertHasErrors(['newLocationCountry']);
});
