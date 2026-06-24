<?php

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\ValueObjects\Money;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\PhotoCleanup\PhotoCleanupException;
use App\Base\Media\PhotoCleanup\PhotoCleanupSelection;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemAttributes;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemCatalogFit;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemFitments;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Inventory\Services\InventoryItemService;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Services\MarketplaceAvailabilitySyncService;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Marketplace\Services\MarketplaceListingPushService;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use App\Modules\Core\AI\Services\AiProviderFamilyRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Show extends Component
{
    use ManagesItemAttributes;
    use ManagesItemCatalogFit;
    use ManagesItemFitments;
    use SavesValidatedFields;
    use WithFileUploads;

    public Item $item;

    /**
     * Photo sub-relations every photo action reads or re-renders. Held in one
     * place so the load sites cannot drift apart (the cleaned/original badge
     * and cleanup actions all depend on both being present).
     *
     * @var list<string>
     */
    private const PHOTO_RELATIONS = ['photos.mediaAsset', 'photos.cleanedAsset', 'photos.cleanedAssets', 'photos.selectedCleanedAsset'];

    /**
     * @var array<int, mixed>
     */
    public array $photoFiles = [];

    public bool $photoReviewModalOpen = false;

    public ?int $photoReviewPhotoId = null;

    public ?string $currencyCode = '';

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item->load([
            'category',
            'productTemplate',
            'fitments',
            'catalogAttributeValues.attribute',
            ...self::PHOTO_RELATIONS,
        ]);
        $this->catalogCategoryId = $this->item->category_id;
        $this->catalogProductTemplateId = $this->item->product_template_id;
        $this->currencyCode = (string) $this->item->currency_code;

        $this->refreshAllChannelReadiness();
    }

    /**
     * Recompute every channel's readiness draft so the Channels panel never
     * shows a stale verdict: on page load and again after each edit that can
     * change readiness. refreshListingDraft is local-only by contract (see
     * MarketplaceChannel), so this is cheap. There is no manual recheck — a
     * channel that fails keeps its last snapshot and the failure is flashed.
     */
    private function refreshAllChannelReadiness(): void
    {
        $registry = app(MarketplaceChannelRegistry::class);
        $item = $this->item->fresh() ?? $this->item;
        $failures = [];

        foreach ($registry->all() as $channel => $descriptor) {
            try {
                $registry->channel($channel)->refreshListingDraft($item);
            } catch (Throwable $exception) {
                $failures[] = $descriptor->label.': '.$exception->getMessage();
            }
        }

        if ($failures !== []) {
            $this->notifyWarning(__('Channel readiness could not be refreshed. :failures', ['failures' => implode(' ', array_slice($failures, 0, 2))]));
        }
    }

    /**
     * Currency uses the shared GeoNames-backed combobox (same as the create form),
     * so it saves on selection rather than through the edit-in-place affordance.
     */
    public function updatedCurrencyCode(?string $value): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }

        $this->authorizeUpdate();

        $validated = validator(
            ['currency_code' => strtoupper(trim($value))],
            ['currency_code' => ['required', 'string', 'size:3']],
        )->validate();

        $this->item->update(['currency_code' => $validated['currency_code']]);
        $this->item->refresh();
        $this->currencyCode = (string) $this->item->currency_code;
        $this->notify(__('Currency updated.'));
    }

    public function pushChannel(string $channel, MarketplaceListingPushService $pushes): void
    {
        $this->authorizeMarketplacePush();

        $rows = $this->channelRows(app(MarketplaceChannelRegistry::class));
        $target = collect($rows)
            ->first(fn (array $row): bool => $row['key'] === $channel && $row['can_push']);

        if (! $target) {
            $this->notifyError(__('This channel is not ready to push yet. Refresh readiness and resolve the blockers first.'));

            return;
        }

        $this->flashPushResult($pushes->push($this->item, [$channel]));
        $this->item->refresh();
    }

    public function saveField(string $field, mixed $value, MarketplaceAvailabilitySyncService $availability): void
    {
        $this->authorizeUpdate();

        if ($field === 'sku') {
            $value = strtoupper(trim((string) $value));
        }

        if ($field === 'storage_location' && trim((string) $value) === '') {
            $value = null;
        }

        $previousQuantity = (int) $this->item->quantity_on_hand;

        $this->saveValidatedField(
            $this->item,
            $field,
            $value,
            $this->fieldRules(),
            function ($model, string $field, mixed $validatedValue): void {
                if ($field === 'currency_code') {
                    $model->currency_code = strtoupper($validatedValue);
                }

                if ($field === 'sku') {
                    $model->sku = strtoupper($validatedValue);
                }

                if ($field === 'notes' && trim((string) $validatedValue) === '') {
                    $model->notes = null;
                }

                if ($field === 'description' && trim((string) $validatedValue) === '') {
                    $model->description = null;
                }
            },
        );

        $this->item->refresh();

        if (in_array($field, ['sku', 'title', 'description', 'quantity_on_hand'], true)) {
            $this->refreshAllChannelReadiness();
        }

        // Inventory is the source of truth for availability: a quantity change
        // (including a sale that drops it to 0) propagates to every channel
        // listing so the same stock cannot be sold twice.
        if ($field === 'quantity_on_hand' && (int) $this->item->quantity_on_hand !== $previousQuantity) {
            $this->flashAvailabilityResult($availability->syncItem($this->item));
        }
    }

    /**
     * @param  array{available: int, ended: list<array<string, mixed>>, revised: list<array<string, mixed>>, skipped: list<array<string, mixed>>, failures: list<array{label: string, message: string}>}  $result
     */
    private function flashAvailabilityResult(array $result): void
    {
        $touched = count($result['ended']) + count($result['revised']);
        $failureCount = count($result['failures']);
        $skippedCount = count($result['skipped']);

        if ($touched === 0 && $failureCount === 0 && $skippedCount === 0) {
            return;
        }

        $skippedNote = $skippedCount > 0
            ? ' '.trans_choice(':count listing needs attention to avoid overselling.|:count listings need attention to avoid overselling.', $skippedCount, ['count' => $skippedCount])
            : '';

        if ($failureCount > 0) {
            $message = collect($result['failures'])
                ->map(fn (array $failure): string => $failure['label'].': '.$failure['message'])
                ->take(2)
                ->implode(' ');

            $this->notifyWarning(__('Availability sync failed on :count channel(s): :message', ['count' => $failureCount, 'message' => $message]).$skippedNote);

            return;
        }

        if ($touched === 0) {
            $this->notifyWarning(trim($skippedNote));

            return;
        }

        if ($result['available'] === 0) {
            $this->notify(trans_choice('Out of stock — ended the listing on :count channel.|Out of stock — ended the listing on :count channels.', count($result['ended']), ['count' => count($result['ended'])]).$skippedNote);

            return;
        }

        $this->notify(trans_choice('Synced availability to :count channel.|Synced availability to :count channels.', $touched, ['count' => $touched]).$skippedNote);
    }

    public function uploadPhotos(InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->validate([
            'photoFiles' => ['required', 'array', 'min:1', 'max:12'],
            'photoFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $uploaded = 0;

        DB::transaction(function () use ($items, &$uploaded): void {
            $maxSort = (int) $this->item->photos()->max('sort_order');
            $sort = $maxSort;

            foreach ($this->photoFiles as $file) {
                if (! $file) {
                    continue;
                }

                $sort++;
                $items->uploadPhoto($this->item, $file, $sort);
                $uploaded++;
            }
        });

        $this->photoFiles = [];
        $this->item->load(self::PHOTO_RELATIONS);
        $this->refreshAllChannelReadiness();

        if ($uploaded > 0) {
            $this->notify(trans_choice('Uploaded :count photo.|Uploaded :count photos.', $uploaded, ['count' => $uploaded]));
        }
    }

    public function deletePhoto(int $photoId, InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $items->deletePhoto($photo);

        $this->item->load(self::PHOTO_RELATIONS);
        if ($this->photoReviewPhotoId === $photoId) {
            $this->photoReviewPhotoId = $this->item->photos->first()?->id;
            $this->photoReviewModalOpen = $this->photoReviewPhotoId !== null && $this->photoReviewModalOpen;
        }

        $this->refreshAllChannelReadiness();
        $this->notify(__('Photo deleted.'));
    }

    public function setPhotoListingSelection(int $photoId, bool $selectedForListing, InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $items->setPhotoSelectedForListing($photo, $selectedForListing);

        $this->item->load(self::PHOTO_RELATIONS);
        $this->refreshAllChannelReadiness();
        $this->notify($selectedForListing ? __('Photo listed.') : __('Photo unlisted.'));
    }

    public function deleteUnselectedPhotos(InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $currentPhotoWasUnselected = $this->photoReviewPhotoId !== null
            && $this->item->photos->contains(fn (ItemPhoto $photo): bool => $photo->id === $this->photoReviewPhotoId && ! $photo->selected_for_listing);

        $deleted = $items->deleteUnselectedPhotos($this->item);

        $this->item->load(self::PHOTO_RELATIONS);

        if ($currentPhotoWasUnselected) {
            $this->photoReviewPhotoId = $this->item->photos->first()?->id;
            $this->photoReviewModalOpen = $this->photoReviewPhotoId !== null && $this->photoReviewModalOpen;
        }

        $this->refreshAllChannelReadiness();

        if ($deleted === 0) {
            $this->notify(__('No unlisted photos to delete.'));

            return;
        }

        $this->notify(trans_choice(':count unlisted photo deleted.|:count unlisted photos deleted.', $deleted, ['count' => $deleted]));
    }

    public function deleteUnselectedCleanedVersions(int $photoId, InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $deleted = $items->deleteUnselectedCleanedAssets($photo);

        $this->item->load(self::PHOTO_RELATIONS);
        $this->refreshAllChannelReadiness();

        if ($deleted === 0) {
            $this->notify(__('No alternate cleaned versions to delete.'));

            return;
        }

        $this->notify(trans_choice(':count alternate cleaned version deleted.|:count alternate cleaned versions deleted.', $deleted, ['count' => $deleted]));
    }

    public function openPhotoReview(int $photoId): void
    {
        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->item->photos->firstWhere('id', $photoId);

        if ($photo instanceof ItemPhoto) {
            $this->photoReviewPhotoId = $photo->id;
            $this->photoReviewModalOpen = true;
        }
    }

    public function closePhotoReview(): void
    {
        $this->photoReviewModalOpen = false;
    }

    public function openFirstCleanedPhotoReview(): void
    {
        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->item->photos
            ->first(fn (ItemPhoto $photo): bool => $photo->cleanedAssets->isNotEmpty() && ! $photo->use_cleaned_photo)
            ?? $this->item->photos->first(fn (ItemPhoto $photo): bool => $photo->cleanedAssets->isNotEmpty());

        if ($photo instanceof ItemPhoto) {
            $this->photoReviewPhotoId = $photo->id;
            $this->photoReviewModalOpen = true;
        }
    }

    public function previousPhotoReview(): void
    {
        $this->movePhotoReview(-1);
    }

    public function nextPhotoReview(): void
    {
        $this->movePhotoReview(1);
    }

    public function setPhotoCleanupProvider(string $providerKey): void
    {
        $this->authorizeUpdate();

        $provider = collect($this->readyPhotoCleanupProviders())
            ->first(fn (array $provider): bool => $provider['key'] === $providerKey);

        if ($provider === null) {
            $this->notifyError(__('That provider is not ready. Add a key first.'));

            return;
        }

        app(PhotoCleanupSelection::class)->setActiveProvider($this->item->company_id, $providerKey);
    }

    /**
     * Run background removal on one photo, creating or replacing its cleaned
     * derivative. Does not change which version (original or cleaned) the item
     * currently uses for listings.
     */
    public function runPhotoCleanup(int $photoId, InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $this->ensureReadyPhotoCleanupProviderSelected();

        try {
            $items->cleanPhoto($photo, $this->item->company_id);
            $this->photoReviewPhotoId = $photo->id;
            $this->photoReviewModalOpen = true;
            $this->notify(__('Photo cleaned. Review the result in the Photos tab before using it on a listing.'));
        } catch (PhotoCleanupException $exception) {
            $this->notifyError($exception->getMessage());
        }

        $this->item->load(self::PHOTO_RELATIONS);
    }

    /**
     * Run background removal on every photo that does not already have a
     * derivative from the active provider. Other providers' derivatives are
     * kept for comparison.
     */
    public function runPhotoCleanupBatch(InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $cleaned = 0;
        $failed = 0;
        $errorMessage = null;

        $activeProvider = $this->ensureReadyPhotoCleanupProviderSelected();
        $providerKey = $activeProvider['key'] ?? app(PhotoCleanupSelection::class)->activeProviderKey($this->item->company_id);

        foreach ($this->item->photos as $photo) {
            if ($photo->cleanedAssetForProvider($providerKey) instanceof MediaAsset) {
                continue;
            }

            try {
                $items->cleanPhoto($photo, $this->item->company_id);
                $cleaned++;
            } catch (PhotoCleanupException $exception) {
                $failed++;
                $errorMessage ??= $exception->getMessage();
            }
        }

        $this->item->load(self::PHOTO_RELATIONS);

        if ($cleaned === 0 && $failed === 0) {
            $this->notify($activeProvider
                ? __('All photos already have a :provider version.', ['provider' => $activeProvider['label']])
                : __('All photos already have a cleaned version.'));

            return;
        }

        if ($failed > 0) {
            $this->notify(__(':cleaned cleaned, :failed failed: :message', [
                'cleaned' => $cleaned,
                'failed' => $failed,
                'message' => $errorMessage,
            ]), $cleaned > 0 ? 'warning' : 'error');

            return;
        }

        $this->notify(trans_choice(':count photo cleaned. Review cleaned photos to choose what listings use.|:count photos cleaned. Review cleaned photos to choose what listings use.', $cleaned, ['count' => $cleaned]));
    }

    /**
     * Switch this photo to use its cleaned derivative for marketplace
     * listings. Reversible via revertCleanedPhoto.
     */
    public function acceptCleanedPhoto(int $photoId, ?int $cleanedAssetId = null): void
    {
        $this->applyUseCleanedPhoto($photoId, true, app(InventoryItemService::class), $cleanedAssetId);
    }

    /**
     * Switch this photo back to its original for marketplace listings. The
     * cleaned derivative is kept, so this can be re-accepted without
     * re-running cleanup.
     */
    public function revertCleanedPhoto(int $photoId, InventoryItemService $items): void
    {
        $this->applyUseCleanedPhoto($photoId, false, $items);
    }

    private function applyUseCleanedPhoto(int $photoId, bool $useCleanedPhoto, InventoryItemService $items, ?int $cleanedAssetId = null): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $cleanedAsset = null;

        if ($useCleanedPhoto) {
            $cleanedAsset = $cleanedAssetId !== null
                ? $photo->cleanedAssets->firstWhere('id', $cleanedAssetId)
                : $photo->activeCleanedAsset();
        }

        if ($useCleanedPhoto && ! ($cleanedAsset instanceof MediaAsset)) {
            $this->notifyError(__('This photo does not have a cleaned version yet.'));

            return;
        }

        $items->setUseCleanedPhoto($photo, $useCleanedPhoto, $cleanedAsset);

        $this->item->load(self::PHOTO_RELATIONS);
        $this->refreshAllChannelReadiness();
        $this->notify($useCleanedPhoto ? __('Cleaned photo selected.') : __('Original photo selected.'));
    }

    public function saveMoneyField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        if (! in_array($field, ['unit_cost_amount', 'target_price_amount'], true)) {
            return;
        }

        try {
            $validated = validator(
                [$field => $value],
                [$field => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/']],
            )->validate();
        } catch (ValidationException $exception) {
            $this->notifyError(__('Price was not saved. Enter a valid amount.'));

            throw $exception;
        }

        $this->item->update([
            $field => Money::fromDecimalString($validated[$field] ?? null, $this->item->currency_code)?->minorAmount,
        ]);
        $this->item->refresh();

        if ($field === 'target_price_amount') {
            $this->refreshAllChannelReadiness();
        }

        $this->notify(__('Price saved.'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function fieldRules(): array
    {
        return [
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Item::class, 'sku')
                    ->where('company_id', $this->item->company_id)
                    ->ignore($this->item),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'quantity_on_hand' => ['required', 'integer', 'min:0', 'max:999999'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'currency_code' => ['required', 'string', 'size:3'],
        ];
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            Item::STATUS_DRAFT => 'default',
            Item::STATUS_READY => 'info',
            Item::STATUS_LISTED => 'accent',
            Item::STATUS_SOLD => 'success',
            Item::STATUS_ARCHIVED => 'default',
            default => 'default',
        };
    }

    public function formatMoney(?int $amount, string $currencyCode): string
    {
        return Money::format($amount, $currencyCode);
    }

    public function canEdit(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'commerce.inventory.item.update')
            ->allowed;
    }

    public function canPushToMarketplace(): bool
    {
        $actor = Actor::forUser(Auth::user());
        $authorization = app(AuthorizationService::class);

        return $authorization->can($actor, 'commerce.inventory.item.update')->allowed
            && $authorization->can($actor, 'commerce.marketplace.execute')->allowed;
    }

    public function listingStatusVariant(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE', 'PUBLISHED' => 'success',
            'UNPUBLISHED', 'ENDED' => 'default',
            'INACTIVE' => 'warning',
            default => 'default',
        };
    }

    /**
     * The eBay category this item inherits from its product template, if the
     * template has been mapped (item metadata first, then a plugin default).
     * Drives the Catalog Fit card hint so the mapping is discoverable where the
     * template is chosen, not only behind a settings-page link.
     *
     * @return array{category_id: string, category_tree_id: string|null}|null
     */
    public function ebayCategoryMapping(): ?array
    {
        $template = $this->item->productTemplate;

        if ($template === null) {
            return null;
        }

        $metadata = $template->metadata ?? [];
        $categoryId = data_get($metadata, 'marketplace.ebay.category_id');
        $categoryTreeId = data_get($metadata, 'marketplace.ebay.category_tree_id');

        if (! is_string($categoryId) || trim($categoryId) === '') {
            $pluginMapping = app(CommercePluginRegistry::class)
                ->marketplaceTemplateMappingForTemplate(EbayConfiguration::CHANNEL, $template);
            $categoryId = $pluginMapping['category_id'] ?? null;
            $categoryTreeId = $pluginMapping['category_tree_id'] ?? $categoryTreeId;
        }

        if (! is_string($categoryId) || trim($categoryId) === '') {
            return null;
        }

        return [
            'category_id' => trim($categoryId),
            'category_tree_id' => is_string($categoryTreeId) && trim($categoryTreeId) !== '' ? trim($categoryTreeId) : null,
        ];
    }

    public function ebayCategorySettingsUrl(): ?string
    {
        return Route::has('commerce.marketplace.ebay.settings')
            ? route('commerce.marketplace.ebay.settings').'#categories'
            : null;
    }

    public function render(): View
    {
        $photoCleanupProviders = $this->readyPhotoCleanupProviders();
        $activePhotoCleanupProviderKey = data_get(collect($photoCleanupProviders)->firstWhere('active', true), 'key')
            ?? app(PhotoCleanupSelection::class)->activeProviderKey($this->item->company_id);

        return view('commerce-inventory::livewire.commerce.inventory.items.show', [
            'statuses' => Item::statuses(),
            'availableAttributes' => $this->applicableAttributeQuery(Auth::user()?->company_id)->get(),
            'categories' => Category::query()
                ->where('company_id', Auth::user()?->company_id)
                ->with('parent.parent.parent.parent.parent')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'productTemplates' => ProductTemplate::query()
                ->where('company_id', Auth::user()?->company_id)
                ->with('category.parent.parent.parent.parent.parent')
                ->orderBy('name')
                ->get(),
            'fitmentSourceItems' => Item::query()
                ->where('company_id', Auth::user()?->company_id)
                ->whereKeyNot($this->item->id)
                ->whereHas('fitments')
                ->withCount('fitments')
                ->orderBy('sku')
                ->limit(100)
                ->get(),
            'canBootstrapFitmentFromAttributes' => $this->canBootstrapFitmentFromAttributes(),
            'channelRows' => $this->channelRows(app(MarketplaceChannelRegistry::class)),
            'extensionReadinessPanels' => app(CommercePluginRegistry::class)->itemReadinessPanels($this->item),
            'photoReviewPhoto' => $this->photoReviewPhoto(),
            'photoReviewPosition' => $this->photoReviewPosition(),
            'photoCleanupProviders' => $photoCleanupProviders,
            'activePhotoCleanupProviderKey' => $activePhotoCleanupProviderKey,
        ]);
    }

    public function formatMoneyInput(?int $amount): ?string
    {
        return Money::formatInput($amount);
    }

    private function authorizeUpdate(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.inventory.item.update',
        );
    }

    private function authorizeMarketplacePush(): void
    {
        $this->authorizeUpdate();

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.execute',
        );
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     icon: string|null,
     *     listing: Listing|null,
     *     draft: ListingDraft|null,
     *     listed: bool,
     *     can_push: bool,
     *     supports_push: bool,
     *     push_disabled_reason: string|null,
     *     readiness_status: string,
     *     readiness_variant: string,
     *     blockers: list<array<string, mixed>>,
     *     warnings: list<array<string, mixed>>,
     *     price_amount: int|null,
     *     currency_code: string,
     *     environment: string|null,
     *     requires_confirmation: bool,
     *     settings_url: string|null,
     *     index_url: string|null
     * }>
     */
    private function channelRows(MarketplaceChannelRegistry $channels): array
    {
        $descriptors = $channels->all();
        $keys = array_keys($descriptors);

        if ($keys === []) {
            return [];
        }

        $listings = Listing::query()
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->whereIn('channel', $keys)
            ->latest('updated_at')
            ->get()
            ->groupBy('channel');

        $drafts = ListingDraft::query()
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->whereIn('channel', $keys)
            ->latest('updated_at')
            ->get()
            ->groupBy('channel');

        return collect($descriptors)
            ->map(function ($descriptor, string $channel) use ($listings, $drafts): array {
                $listing = $listings->get($channel)?->first();
                $draft = $drafts->get($channel)?->first();
                $readinessStatus = $draft?->readiness_status ?? 'unchecked';
                $listed = $listing instanceof Listing
                    && $listing->ended_at === null
                    && ! in_array(strtoupper((string) $listing->status), ['ENDED', 'UNPUBLISHED'], true);
                $operation = $listed ? 'revise_listing' : 'create_listing';
                $supportsPush = $descriptor->supports($operation);
                $blockers = collect(data_get($draft?->readiness_snapshot, 'blockers', []))
                    ->filter(fn (mixed $gap): bool => is_array($gap))
                    ->values()
                    ->all();
                $warnings = collect(data_get($draft?->readiness_snapshot, 'warnings', []))
                    ->filter(fn (mixed $gap): bool => is_array($gap))
                    ->values()
                    ->all();
                $environment = data_get($draft?->readiness_snapshot, 'facts.environment');
                $canPush = $supportsPush && $readinessStatus === 'ready';
                $pushDisabledReason = match (true) {
                    $canPush => null,
                    ! $supportsPush => __('This channel does not support publishing from Belimbing yet.'),
                    $readinessStatus === 'blocked' => trans_choice('Resolve the blocker below first.|Resolve the :count blockers below first.', count($blockers), ['count' => count($blockers)]),
                    default => __('Run a readiness check first.'),
                };

                return [
                    'key' => $channel,
                    'label' => $descriptor->label,
                    'icon' => $descriptor->icon,
                    'listing' => $listing instanceof Listing ? $listing : null,
                    'draft' => $draft instanceof ListingDraft ? $draft : null,
                    'listed' => $listed,
                    'can_push' => $canPush,
                    'supports_push' => $supportsPush,
                    'push_disabled_reason' => $pushDisabledReason,
                    'readiness_status' => $readinessStatus,
                    'readiness_variant' => $this->readinessVariant($readinessStatus),
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'price_amount' => $listing?->price_amount ?? $this->item->target_price_amount,
                    'currency_code' => $listing?->currency_code ?? $this->item->currency_code,
                    'environment' => is_string($environment) && trim($environment) !== '' ? trim($environment) : null,
                    'requires_confirmation' => $environment === 'live',
                    'settings_url' => $this->routeUrl($descriptor->routes['settings'] ?? null),
                    'index_url' => $this->routeUrl($descriptor->routes['index'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function readinessVariant(string $status): string
    {
        return match ($status) {
            'ready' => 'success',
            'blocked' => 'warning',
            default => 'default',
        };
    }

    private function routeUrl(mixed $routeName): ?string
    {
        return is_string($routeName) && Route::has($routeName) ? route($routeName) : null;
    }

    private function movePhotoReview(int $direction): void
    {
        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photoIds = $this->item->photos->pluck('id')->values()->all();
        $photoCount = count($photoIds);

        if ($photoCount === 0) {
            $this->photoReviewPhotoId = null;

            return;
        }

        $currentIndex = array_search($this->photoReviewPhotoId, $photoIds, true);
        $currentIndex = is_int($currentIndex) ? $currentIndex : 0;
        $nextIndex = ($currentIndex + $direction + $photoCount) % $photoCount;

        $this->photoReviewPhotoId = (int) $photoIds[$nextIndex];
    }

    private function photoReviewPhoto(): ?ItemPhoto
    {
        $this->item->loadMissing(self::PHOTO_RELATIONS);

        $photo = $this->photoReviewPhotoId === null
            ? $this->item->photos->first()
            : $this->item->photos->firstWhere('id', $this->photoReviewPhotoId);

        if ($photo instanceof ItemPhoto) {
            return $photo;
        }

        $fallback = $this->item->photos->first();

        return $fallback instanceof ItemPhoto ? $fallback : null;
    }

    /**
     * @return array{current: int, total: int}
     */
    private function photoReviewPosition(): array
    {
        $this->item->loadMissing(self::PHOTO_RELATIONS);
        $photoIds = $this->item->photos->pluck('id')->values();
        $index = $photoIds->search($this->photoReviewPhoto()?->id, true);

        return [
            'current' => is_int($index) ? $index + 1 : 0,
            'total' => $photoIds->count(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, active: bool}>
     */
    private function readyPhotoCleanupProviders(): array
    {
        $providers = app(AiProviderFamilyRegistry::class)
            ->family('image')
            ?->providers($this->item->company_id) ?? [];

        $readyProviders = collect($providers)
            ->filter(fn ($provider): bool => $provider->connected)
            ->map(fn ($provider): array => [
                'key' => $provider->providerKey,
                'label' => $provider->displayName,
                'active' => $provider->active,
            ])
            ->values()
            ->all();

        if ($readyProviders !== [] && ! collect($readyProviders)->contains(fn (array $provider): bool => $provider['active'])) {
            $readyProviders[0]['active'] = true;
        }

        return $readyProviders;
    }

    /**
     * @return array{key: string, label: string, active: bool}|null
     */
    private function ensureReadyPhotoCleanupProviderSelected(): ?array
    {
        $provider = collect($this->readyPhotoCleanupProviders())->firstWhere('active', true);

        if (! is_array($provider)) {
            return null;
        }

        if ($provider['key'] !== app(PhotoCleanupSelection::class)->activeProviderKey($this->item->company_id)) {
            app(PhotoCleanupSelection::class)->setActiveProvider($this->item->company_id, $provider['key']);
        }

        return $provider;
    }

    /**
     * @param  array{results: list<array{channel: string, label: string, operation: string, payload: array<string, mixed>}>, failures: list<array{channel: string, label: string, message: string}>}  $result
     */
    private function flashPushResult(array $result): void
    {
        $successCount = count($result['results']);
        $failureCount = count($result['failures']);
        $failureSummary = collect($result['failures'])
            ->map(fn (array $failure): string => $failure['label'].': '.$failure['message'])
            ->take(2)
            ->implode(' ');

        if ($failureCount === 0) {
            $this->notify(trans_choice('Pushed :count marketplace channel.|Pushed :count marketplace channels.', $successCount, ['count' => $successCount]));

            return;
        }

        if ($successCount > 0) {
            $this->notifyWarning(__('Pushed :success channel(s), but :failed failed: :message', [
                'success' => $successCount,
                'failed' => $failureCount,
                'message' => $failureSummary,
            ]));

            return;
        }

        $this->notifyError(__('Nothing was pushed: :message', ['message' => $failureSummary]));
    }
}
