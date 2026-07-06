<?php

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Facades\File;

function configureHardeningWebhook(): void
{
    $settings = app(SettingsService::class);
    $settings->set('commerce.marketplace.ebay.deletion_verification_token', 'a-verification-token-of-sufficient-length');
    $settings->set('commerce.marketplace.ebay.deletion_endpoint_url', 'https://edge.example.test/webhooks/ebay/account-deletion');
}

function deletionLogPath(): string
{
    return storage_path('logs/ebay-account-deletion.log');
}

beforeEach(function (): void {
    configureHardeningWebhook();

    if (File::exists(deletionLogPath())) {
        File::delete(deletionLogPath());
    }
});

test('an oversized notification body is truncated in the log', function (): void {
    $huge = str_repeat('A', 50_000);

    $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), [
        'metadata' => ['topic' => 'MARKETPLACE_ACCOUNT_DELETION'],
        'blob' => $huge,
    ])->assertOk()->assertExactJson(['received' => true]);

    $logged = File::exists(deletionLogPath()) ? File::get(deletionLogPath()) : '';

    // The full 50k body is never persisted; the cap keeps the record small.
    expect(strlen($logged))->toBeLessThan(10_000)
        ->and($logged)->toContain('body_bytes');
});

test('a crafted body cannot inject extra log lines', function (): void {
    $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), [
        'metadata' => ['topic' => "evil\ninjected fake log line"],
    ])->assertOk();

    $logged = File::exists(deletionLogPath()) ? File::get(deletionLogPath()) : '';

    // The newline is JSON-escaped, so it cannot forge a standalone log entry.
    expect($logged)->not->toContain("\ninjected fake log line")
        ->and($logged)->toContain('injected fake log line');
});

test('the endpoint is rate limited', function (): void {
    // Exhaust the 120/min bucket, then expect a 429.
    for ($i = 0; $i < 120; $i++) {
        $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), ['n' => $i]);
    }

    $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), ['n' => 'over'])
        ->assertStatus(429);
});
