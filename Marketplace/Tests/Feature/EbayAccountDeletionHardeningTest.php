<?php

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Facades\File;

function configureEbayDeletionHardeningWebhook(): void
{
    $settings = app(SettingsService::class);
    $settings->set('commerce.marketplace.ebay.deletion_verification_token', 'a-verification-token-of-sufficient-length');
    $settings->set('commerce.marketplace.ebay.deletion_endpoint_url', 'https://edge.example.test/webhooks/ebay/account-deletion');
}

function ebayDeletionHardeningLogPath(): string
{
    return storage_path('logs/ebay-account-deletion.log');
}

/**
 * @return array<string, mixed>
 */
function latestEbayDeletionHardeningLogContext(): array
{
    $logged = File::exists(ebayDeletionHardeningLogPath()) ? trim(File::get(ebayDeletionHardeningLogPath())) : '';
    preg_match('/\s(\{.*\})$/', $logged, $matches);

    return json_decode($matches[1] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    configureEbayDeletionHardeningWebhook();

    if (File::exists(ebayDeletionHardeningLogPath())) {
        File::delete(ebayDeletionHardeningLogPath());
    }
});

test('an oversized notification body is truncated in the log', function (): void {
    $huge = str_repeat('A', 50_000);

    $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), [
        'metadata' => ['topic' => 'MARKETPLACE_ACCOUNT_DELETION'],
        'blob' => $huge,
    ])->assertOk()->assertExactJson(['received' => true]);

    $logged = File::exists(ebayDeletionHardeningLogPath()) ? File::get(ebayDeletionHardeningLogPath()) : '';
    $context = latestEbayDeletionHardeningLogContext();

    // The full 50k body is never persisted; the cap keeps the record small.
    expect(strlen((string) $context['body']))->toBe(4096)
        ->and($logged)->not->toContain(str_repeat('A', 4097))
        ->and($logged)->toContain('body_bytes');
});

test('a crafted body cannot inject physical log lines', function (): void {
    $body = <<<'JSON'
{
  "metadata": {
    "topic": "evil\ninjected fake log line"
  }
}
JSON;

    $this->call(
        'POST',
        route('commerce.marketplace.ebay.webhooks.account-deletion'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $body,
    )->assertOk();

    $logged = File::exists(ebayDeletionHardeningLogPath()) ? File::get(ebayDeletionHardeningLogPath()) : '';
    $context = latestEbayDeletionHardeningLogContext();

    // The log record stays physically one line even when the raw body contains
    // real newlines and a decoded field contains an escaped newline.
    expect(preg_split('/\R/', trim($logged)))->toHaveCount(1)
        ->and($context['body'])->not->toContain("\n")
        ->and($context['topic'])->toBe('evil injected fake log line');
});

test('the endpoint is rate limited', function (): void {
    // Exhaust the 120/min bucket, then expect a 429.
    for ($i = 0; $i < 120; $i++) {
        $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), ['n' => $i]);
    }

    $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), ['n' => 'over'])
        ->assertStatus(429);
});
