<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use SimpleXMLElement;

/**
 * Thin client for the legacy eBay Trading API (XML over /ws/api.dll), used to
 * read listings the Inventory API cannot see — listings created in Seller Hub or
 * before the store was connected. The OAuth user token is passed via the
 * X-EBAY-API-IAF-TOKEN header, so no separate Auth'n'Auth token is needed.
 */
class EbayTradingService
{
    private const EBAY_NS = 'urn:ebay:apis:eBLBaseComponents';

    private const COMPATIBILITY_LEVEL = '1267';

    private const ENTRIES_PER_PAGE = 200;

    /** Trading API site IDs keyed by REST marketplace id (default US/0). */
    private const SITE_IDS = [
        'EBAY_US' => '0',
        'EBAY_MOTORS' => '100',
        'EBAY_CA' => '2',
        'EBAY_GB' => '3',
        'EBAY_AU' => '15',
        'EBAY_DE' => '77',
    ];

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * Fetch the seller's active listings via GetMyeBaySelling, paginated up to
     * $maxPages (200 per page). `complete` is true only when every page was read —
     * reconciliation must not end "missing" listings off a partial set.
     *
     * @return array{listings: list<array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, listing_type: string|null, view_url: string|null}>, complete: bool}
     */
    public function fetchActiveListings(int $companyId, int $maxPages = 10): array
    {
        $config = $this->configuration->forCompany($companyId);
        $accessToken = $this->oauth->accessToken($companyId);
        $siteId = self::SITE_IDS[(string) ($config['marketplace_id'] ?? '')] ?? '0';

        $listings = [];
        $page = 1;
        $totalPages = 1;

        do {
            $xml = $this->call($companyId, $config, $accessToken, $siteId, 'GetMyeBaySelling', $this->activeListRequest($page));

            foreach ($xml->xpath('//e:ActiveList/e:ItemArray/e:Item') ?: [] as $item) {
                $summary = $this->summarizeItem($item);

                if ($summary !== null) {
                    $listings[$summary['item_id']] = $summary;
                }
            }

            $totalPages = (int) $this->value($xml, '//e:ActiveList/e:PaginationResult/e:TotalNumberOfPages');
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return [
            'listings' => array_values($listings),
            'complete' => $totalPages <= $maxPages,
        ];
    }

    /**
     * Fetch one listing's full detail via GetItem — the enrichment source for
     * adopting Seller-Hub/legacy listings the Inventory API cannot see (photos,
     * item specifics, parts compatibility, description, category). Read-only.
     *
     * @return array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, description: string|null, condition_id: string|null, condition_display: string|null, category_id: string|null, photo_urls: list<string>, specifics: array<string, string>, compatibility: list<array{year: string|null, make: string|null, model: string|null, trim: string|null, engine: string|null, properties: array<string, string>}>}
     */
    public function getItem(int $companyId, string $listingId): array
    {
        $config = $this->configuration->forCompany($companyId);
        $accessToken = $this->oauth->accessToken($companyId);
        $siteId = self::SITE_IDS[(string) ($config['marketplace_id'] ?? '')] ?? '0';

        $xml = $this->call($companyId, $config, $accessToken, $siteId, 'GetItem', $this->getItemRequest($listingId));

        $itemNodes = $xml->xpath('//e:Item') ?: [];
        $item = $itemNodes[0] ?? null;

        if (! $item instanceof SimpleXMLElement) {
            throw new MarketplaceOperationException(
                (string) __('eBay returned no item detail for listing :id.', ['id' => $listingId]),
            );
        }

        return $this->parseItemDetail($item, $listingId);
    }

    private function getItemRequest(string $itemId): string
    {
        $escapedId = htmlspecialchars($itemId, ENT_QUOTES | ENT_XML1);

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
          <ErrorLanguage>en_US</ErrorLanguage>
          <WarningLevel>High</WarningLevel>
          <ItemID>{$escapedId}</ItemID>
          <DetailLevel>ReturnAll</DetailLevel>
          <IncludeItemSpecifics>true</IncludeItemSpecifics>
          <IncludeItemCompatibilityList>true</IncludeItemCompatibilityList>
        </GetItemRequest>
        XML;
    }

    /**
     * @return array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, description: string|null, condition_id: string|null, condition_display: string|null, category_id: string|null, photo_urls: list<string>, specifics: array<string, string>, compatibility: list<array{year: string|null, make: string|null, model: string|null, trim: string|null, engine: string|null, properties: array<string, string>}>}
     */
    private function parseItemDetail(SimpleXMLElement $item, string $fallbackId): array
    {
        $item->registerXPathNamespace('e', self::EBAY_NS);

        $itemId = trim($this->value($item, 'e:ItemID'));

        // Prefer the live selling price; fall back to the listing start price.
        $priceNodes = $item->xpath('e:SellingStatus/e:CurrentPrice') ?: ($item->xpath('e:StartPrice') ?: []);
        $priceNode = $priceNodes[0] ?? null;
        $priceValue = $priceNode !== null ? (float) (string) $priceNode : null;
        $currency = $priceNode !== null ? (string) ($priceNode['currencyID'] ?? '') : '';

        $quantity = $this->value($item, 'e:Quantity');
        $sold = $this->value($item, 'e:SellingStatus/e:QuantitySold');
        $available = $quantity !== '' ? max(0, (int) $quantity - (int) $sold) : null;

        $sku = trim($this->value($item, 'e:SKU'));
        $description = trim($this->value($item, 'e:Description'));
        $categoryId = trim($this->value($item, 'e:PrimaryCategory/e:CategoryID'));
        $conditionId = trim($this->value($item, 'e:ConditionID'));
        $conditionDisplay = trim($this->value($item, 'e:ConditionDisplayName'));

        $photos = [];
        foreach ($item->xpath('e:PictureDetails/e:PictureURL') ?: [] as $url) {
            $value = trim((string) $url);
            if ($value !== '' && ! in_array($value, $photos, true)) {
                $photos[] = $value;
            }
        }

        $specifics = [];
        foreach ($item->xpath('e:ItemSpecifics/e:NameValueList') ?: [] as $nv) {
            $nv->registerXPathNamespace('e', self::EBAY_NS);
            $name = trim($this->value($nv, 'e:Name'));
            $value = trim($this->value($nv, 'e:Value'));
            if ($name !== '' && $value !== '' && ! array_key_exists($name, $specifics)) {
                $specifics[$name] = $value;
            }
        }

        $compatibility = [];
        foreach ($item->xpath('e:ItemCompatibilityList/e:Compatibility') ?: [] as $compat) {
            $compat->registerXPathNamespace('e', self::EBAY_NS);
            $properties = [];
            foreach ($compat->xpath('e:NameValueList') ?: [] as $nv) {
                $nv->registerXPathNamespace('e', self::EBAY_NS);
                $name = trim($this->value($nv, 'e:Name'));
                $value = trim($this->value($nv, 'e:Value'));
                if ($name !== '' && $value !== '') {
                    $properties[$name] = $value;
                }
            }

            if ($properties === []) {
                continue;
            }

            $compatibility[] = [
                'year' => $this->compatProp($properties, 'Year'),
                'make' => $this->compatProp($properties, 'Make'),
                'model' => $this->compatProp($properties, 'Model'),
                'trim' => $this->compatProp($properties, 'Trim'),
                'engine' => $this->compatProp($properties, 'Engine'),
                'properties' => $properties,
            ];
        }

        return [
            'item_id' => $itemId !== '' ? $itemId : $fallbackId,
            'title' => trim($this->value($item, 'e:Title')),
            'sku' => $sku !== '' ? $sku : null,
            'price_amount' => $priceValue !== null ? (int) round($priceValue * 100) : null,
            'currency_code' => $currency !== '' ? strtoupper($currency) : null,
            'quantity' => $available,
            'description' => $description !== '' ? $description : null,
            'condition_id' => $conditionId !== '' ? $conditionId : null,
            'condition_display' => $conditionDisplay !== '' ? $conditionDisplay : null,
            'category_id' => $categoryId !== '' ? $categoryId : null,
            'photo_urls' => $photos,
            'specifics' => $specifics,
            'compatibility' => $compatibility,
        ];
    }

    /**
     * @param  array<string, string>  $properties
     */
    private function compatProp(array $properties, string $key): ?string
    {
        return isset($properties[$key]) && trim($properties[$key]) !== '' ? trim($properties[$key]) : null;
    }

    private function activeListRequest(int $page): string
    {
        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
          <ErrorLanguage>en_US</ErrorLanguage>
          <WarningLevel>High</WarningLevel>
          <ActiveList>
            <Include>true</Include>
            <Pagination>
              <EntriesPerPage>{$this->entriesPerPage()}</EntriesPerPage>
              <PageNumber>{$page}</PageNumber>
            </Pagination>
          </ActiveList>
        </GetMyeBaySellingRequest>
        XML;
    }

    private function entriesPerPage(): int
    {
        return self::ENTRIES_PER_PAGE;
    }

    /**
     * @return array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, listing_type: string|null, view_url: string|null}|null
     */
    private function summarizeItem(SimpleXMLElement $item): ?array
    {
        $item->registerXPathNamespace('e', self::EBAY_NS);
        $itemId = trim($this->value($item, 'e:ItemID'));

        if ($itemId === '') {
            return null;
        }

        $priceNodes = $item->xpath('e:SellingStatus/e:CurrentPrice') ?: [];
        $priceNode = $priceNodes[0] ?? null;
        $priceValue = $priceNode !== null ? (float) (string) $priceNode : null;
        $currency = $priceNode !== null ? (string) ($priceNode['currencyID'] ?? '') : '';

        $quantity = $this->value($item, 'e:Quantity');
        $sold = $this->value($item, 'e:SellingStatus/e:QuantitySold');
        $available = $quantity !== '' ? max(0, (int) $quantity - (int) $sold) : null;

        $sku = trim($this->value($item, 'e:SKU'));
        $viewUrl = trim($this->value($item, 'e:ListingDetails/e:ViewItemURL'));
        $listingType = trim($this->value($item, 'e:ListingType'));

        return [
            'item_id' => $itemId,
            'title' => trim($this->value($item, 'e:Title')),
            'sku' => $sku !== '' ? $sku : null,
            'price_amount' => $priceValue !== null ? (int) round($priceValue * 100) : null,
            'currency_code' => $currency !== '' ? strtoupper($currency) : null,
            'quantity' => $available,
            'listing_type' => $listingType !== '' ? $listingType : null,
            'view_url' => $viewUrl !== '' ? $viewUrl : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function call(int $companyId, array $config, string $accessToken, string $siteId, string $callName, string $body): SimpleXMLElement
    {
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.trading.'.strtolower($callName),
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/ws/api.dll',
            protocol: 'xml',
            protocolOperation: $callName,
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'X-EBAY-API-CALL-NAME' => $callName,
                'X-EBAY-API-SITEID' => $siteId,
                'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPATIBILITY_LEVEL,
                'X-EBAY-API-IAF-TOKEN' => $accessToken,
                'Content-Type' => 'application/xml',
            ],
            body: $body,
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 45,
            retryTimes: 1,
            asJson: false,
            metadata: ['marketplace_id' => $config['marketplace_id'] ?? null],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'trading.'.strtolower($callName),
                $response->status,
                $response->exchange?->id,
            );
        }

        $xml = $this->parse($response->body);

        $ack = $this->value($xml, '//e:Ack');

        if (strtolower($ack) === 'failure') {
            throw new MarketplaceOperationException($this->errorMessage($xml));
        }

        return $xml;
    }

    private function parse(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new MarketplaceOperationException('eBay returned an unreadable Trading API response.');
        }

        $xml->registerXPathNamespace('e', self::EBAY_NS);

        return $xml;
    }

    private function errorMessage(SimpleXMLElement $xml): string
    {
        $message = $this->value($xml, '//e:Errors/e:LongMessage');

        if (trim($message) === '') {
            $message = $this->value($xml, '//e:Errors/e:ShortMessage');
        }

        return trim($message) !== ''
            ? trim($message)
            : (string) __('eBay rejected the Trading API request.');
    }

    private function value(SimpleXMLElement $node, string $xpath): string
    {
        $found = $node->xpath($xpath) ?: [];

        return $found === [] ? '' : (string) $found[0];
    }
}
