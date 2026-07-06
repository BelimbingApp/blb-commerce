<?php

namespace App\Modules\Commerce\Marketplace\Ebay\Http\Controllers;

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public endpoint for eBay's mandatory marketplace-account-deletion
 * notifications — the compliance gate that unlocks a production keyset.
 * Reached through the Cloudflare tunnel; BLB itself stays private.
 *
 * GET is eBay's ownership handshake: echo back
 * sha256(challengeCode . verificationToken . endpointUrl). POST is a real
 * deletion notice: acknowledge immediately with 200 and record the payload
 * for the operator's buyer-data deletion process — eBay retries and
 * eventually flags the keyset when acknowledgements fail, so nothing here
 * may depend on downstream processing.
 *
 * Both inputs are settings (global layer), changeable without a restart:
 * - commerce.marketplace.ebay.deletion_verification_token (32–80 chars,
 *   chosen by us, entered identically in the eBay Developer Console)
 * - commerce.marketplace.ebay.deletion_endpoint_url (the exact public URL
 *   registered with eBay — part of the challenge hash)
 */
class EbayAccountDeletionController
{
    /**
     * Cap on the notification body written to the log. eBay's payloads are
     * small; bounding what we persist stops an unauthenticated caller from
     * filling the disk by POSTing large bodies to this public endpoint.
     */
    private const MAX_LOGGED_BODY_BYTES = 4096;

    public function __invoke(Request $request, SettingsService $settings): JsonResponse
    {
        $token = (string) $settings->get('commerce.marketplace.ebay.deletion_verification_token', '');

        if (trim($token) === '') {
            // Unconfigured endpoints must not answer the handshake: a 503 keeps
            // eBay's validation failing loudly instead of passing with a hash
            // built from an empty token.
            return response()->json(['error' => 'Account-deletion endpoint is not configured.'], 503);
        }

        if ($request->isMethod('GET')) {
            $challengeCode = trim((string) $request->query('challenge_code', ''));

            if ($challengeCode === '') {
                return response()->json(['error' => 'Missing challenge_code.'], 400);
            }

            $endpointUrl = (string) $settings->get('commerce.marketplace.ebay.deletion_endpoint_url', '');

            return response()->json([
                'challengeResponse' => hash('sha256', $challengeCode.$token.$endpointUrl),
            ]);
        }

        // The endpoint is public and unauthenticated (eBay cannot carry a CSRF
        // token), so an attacker can POST arbitrary bodies. We must still ack
        // with 200 — eBay flags the keyset when acknowledgements fail — but we
        // never trust the payload: real line breaks are stripped so a crafted
        // body cannot forge log lines, and the body is size-capped so it cannot
        // exhaust the disk. Full cryptographic verification of x-ebay-signature
        // is tracked as residual hardening (needs eBay's live public key).
        $rawBody = (string) $request->getContent();

        $singleLine = static fn (string $value): string => (string) preg_replace('/[\r\n]+/', ' ', $value);

        blb_log_var(
            'eBay account-deletion notification received.',
            'ebay-account-deletion.log',
            [
                'topic' => Str::limit($singleLine((string) $request->json('metadata.topic', '')), 120),
                'body' => Str::limit($singleLine($rawBody), self::MAX_LOGGED_BODY_BYTES),
                'body_bytes' => strlen($rawBody),
                'ebay_signature_present' => $request->hasHeader('x-ebay-signature'),
            ],
        );

        return response()->json(['received' => true]);
    }
}
