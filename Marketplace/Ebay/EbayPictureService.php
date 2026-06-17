<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Media\Models\MediaAsset;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

/**
 * Hosts item photos on eBay Picture Services via the Trading API's
 * UploadSiteHostedPictures call. BLB media lives on private disks the
 * marketplace cannot fetch from, so the push pipeline uploads the bytes and
 * memoizes the returned EPS URL on the media asset (`metadata.public_url`),
 * where the payload builder and readiness facts already look. Re-pushes
 * reuse the stored URL without re-uploading.
 */
class EbayPictureService
{
    private const EBAY_NS = 'urn:ebay:apis:eBLBaseComponents';

    private const COMPATIBILITY_LEVEL = '1267';

    /**
     * EPS purges pictures no listing references after this many days (30 is
     * the maximum). Once a listing uses the URL it persists, so a draft only
     * risks expiry when it stays unpublished for a month — the next push
     * simply re-uploads because the URL is validated per push.
     */
    private const EXTENSION_IN_DAYS = 30;

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * Ensure every photo on the item has an eBay-hosted HTTPS URL, uploading
     * those that have none. Returns the URLs in photo sort order.
     *
     * @return list<string>
     */
    public function ensureHostedPhotos(Item $item): array
    {
        $item->loadMissing('photos.mediaAsset', 'photos.cleanedAsset');

        $config = null;
        $accessToken = null;
        $urls = [];

        foreach ($item->photos->sortBy('sort_order')->values() as $photo) {
            $asset = $photo->displayAsset();

            if (! $asset instanceof MediaAsset) {
                continue;
            }

            $existing = $asset->metadata['public_url'] ?? null;

            if (is_string($existing) && str_starts_with($existing, 'https://')) {
                $urls[] = $existing;

                continue;
            }

            $config ??= $this->configuration->forCompany($item->company_id);
            $accessToken ??= $this->oauth->accessToken($item->company_id);

            $url = $this->upload($item, $asset, $config, $accessToken);

            $asset->metadata = [
                ...($asset->metadata ?? []),
                'public_url' => $url,
                'public_url_source' => 'ebay_eps',
                'public_url_uploaded_at' => now()->toIso8601String(),
            ];
            $asset->save();

            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function upload(Item $item, MediaAsset $asset, array $config, string $accessToken): string
    {
        $contents = Storage::disk($asset->disk)->get($asset->storage_key);

        if ($contents === null || $contents === '') {
            throw new MarketplaceOperationException(
                __('Photo file ":file" is missing from storage and cannot be uploaded to eBay.', ['file' => (string) $asset->original_filename]),
            );
        }

        $pictureName = trim($item->sku.' '.$asset->id);
        $requestXml = <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
          <ErrorLanguage>en_US</ErrorLanguage>
          <WarningLevel>High</WarningLevel>
          <PictureName>{$this->xmlEscape($pictureName)}</PictureName>
          <ExtensionInDays>{$this->extensionInDays()}</ExtensionInDays>
        </UploadSiteHostedPicturesRequest>
        XML;

        $boundary = 'EPS'.bin2hex(random_bytes(16));
        $filename = (string) ($asset->original_filename ?? 'photo-'.$asset->id);
        $mimeType = (string) ($asset->mime_type ?? 'application/octet-stream');
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"XML Payload\"\r\n"
            ."Content-Type: text/xml; charset=utf-8\r\n\r\n"
            .$requestXml."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"image\"; filename=\"{$filename}\"\r\n"
            ."Content-Type: {$mimeType}\r\n"
            ."Content-Transfer-Encoding: binary\r\n\r\n"
            .$contents."\r\n"
            ."--{$boundary}--\r\n";

        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.trading.uploadsitehostedpictures',
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/ws/api.dll',
            protocol: 'xml',
            protocolOperation: 'UploadSiteHostedPictures',
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'X-EBAY-API-CALL-NAME' => 'UploadSiteHostedPictures',
                'X-EBAY-API-SITEID' => '0',
                'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPATIBILITY_LEVEL,
                'X-EBAY-API-IAF-TOKEN' => $accessToken,
                'Content-Type' => 'multipart/form-data; boundary='.$boundary,
            ],
            body: $body,
            ownerType: 'company',
            ownerId: $item->company_id,
            timeoutSeconds: 60,
            retryTimes: 1,
            asJson: false,
            metadata: [
                'marketplace_id' => $config['marketplace_id'] ?? null,
                'item_id' => $item->id,
                'media_asset_id' => $asset->id,
            ],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'trading.uploadsitehostedpictures',
                $response->status,
                $response->exchange?->id,
            );
        }

        return $this->fullUrl($response->body);
    }

    private function fullUrl(string $body): string
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new MarketplaceOperationException(__('eBay returned an unreadable picture upload response.'));
        }

        $xml->registerXPathNamespace('e', self::EBAY_NS);

        if (strtolower($this->value($xml, '//e:Ack')) === 'failure') {
            $message = $this->value($xml, '//e:Errors/e:LongMessage') ?: $this->value($xml, '//e:Errors/e:ShortMessage');

            throw new MarketplaceOperationException(
                trim($message) !== '' ? trim($message) : (string) __('eBay rejected the picture upload.'),
            );
        }

        $url = trim($this->value($xml, '//e:SiteHostedPictureDetails/e:FullURL'));

        if ($url === '') {
            throw new MarketplaceOperationException(__('eBay accepted the picture upload but returned no hosted URL.'));
        }

        // EPS serves both schemes from the same host; listings require https.
        return preg_replace('/^http:\/\//', 'https://', $url) ?? $url;
    }

    private function extensionInDays(): int
    {
        return self::EXTENSION_IN_DAYS;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function value(SimpleXMLElement $node, string $xpath): string
    {
        $found = $node->xpath($xpath) ?: [];

        return $found === [] ? '' : (string) $found[0];
    }
}
