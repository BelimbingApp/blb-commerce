<?php

use App\Base\Settings\Contracts\SettingsService;

function configureDeletionWebhook(string $token, string $endpointUrl): void
{
    $settings = app(SettingsService::class);
    $settings->set('commerce.marketplace.ebay.deletion_verification_token', $token);
    $settings->set('commerce.marketplace.ebay.deletion_endpoint_url', $endpointUrl);
}

test('account deletion challenge handshake answers with the spec hash', function (): void {
    configureDeletionWebhook('a-verification-token-of-sufficient-length', 'https://edge.example.test/webhooks/ebay/account-deletion');

    // eBay expects sha256(challengeCode . verificationToken . endpointUrl).
    $expected = hash('sha256', 'challenge-123'.'a-verification-token-of-sufficient-length'.'https://edge.example.test/webhooks/ebay/account-deletion');

    $this->getJson(route('commerce.marketplace.ebay.webhooks.account-deletion', ['challenge_code' => 'challenge-123']))
        ->assertOk()
        ->assertExactJson(['challengeResponse' => $expected]);
});

test('account deletion notifications are acknowledged without auth or csrf', function (): void {
    configureDeletionWebhook('a-verification-token-of-sufficient-length', 'https://edge.example.test/webhooks/ebay/account-deletion');

    // No session, no CSRF token, no login — exactly how eBay calls it.
    $this->postJson(route('commerce.marketplace.ebay.webhooks.account-deletion'), [
        'metadata' => ['topic' => 'MARKETPLACE_ACCOUNT_DELETION'],
        'notification' => ['data' => ['username' => 'buyer-1']],
    ])
        ->assertOk()
        ->assertExactJson(['received' => true]);
});

test('account deletion endpoint refuses the handshake until configured', function (): void {
    $this->getJson(route('commerce.marketplace.ebay.webhooks.account-deletion', ['challenge_code' => 'challenge-123']))
        ->assertStatus(503);
});

test('account deletion handshake requires a challenge code', function (): void {
    configureDeletionWebhook('a-verification-token-of-sufficient-length', 'https://edge.example.test/webhooks/ebay/account-deletion');

    $this->getJson(route('commerce.marketplace.ebay.webhooks.account-deletion'))
        ->assertStatus(400);
});
