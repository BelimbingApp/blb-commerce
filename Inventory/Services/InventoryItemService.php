<?php

namespace App\Modules\Commerce\Inventory\Services;

use App\Base\Foundation\ValueObjects\Money;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\PhotoCleanup\PhotoCleanupService;
use App\Base\Media\Services\MediaAssetStore;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class InventoryItemService
{
    private const string PHOTO_DISK = 'local';

    public function __construct(
        private readonly MediaAssetStore $mediaAssets,
        private readonly PhotoCleanupService $photoCleanup,
    ) {}

    /**
     * @param  array{sku: string, title: string, notes?: string|null, status: string, quantityOnHand?: int|string|null, storageLocation?: string|null, unitCostAmount?: string|null, targetPriceAmount?: string|null, currencyCode: string, categoryId?: int|null, productTemplateId?: int|null}  $data
     */
    public function create(int $companyId, array $data): Item
    {
        $currencyCode = strtoupper($data['currencyCode']);

        return Item::query()->create([
            'company_id' => $companyId,
            'category_id' => $data['categoryId'] ?? null,
            'product_template_id' => $data['productTemplateId'] ?? null,
            'sku' => strtoupper($data['sku']),
            'status' => $data['status'],
            'title' => $data['title'],
            'quantity_on_hand' => max(0, (int) ($data['quantityOnHand'] ?? 1)),
            'storage_location' => trim((string) ($data['storageLocation'] ?? '')) !== '' ? trim((string) $data['storageLocation']) : null,
            'notes' => $data['notes'] ?? null,
            'unit_cost_amount' => Money::fromDecimalString($data['unitCostAmount'] ?? null, $currencyCode)?->minorAmount,
            'target_price_amount' => Money::fromDecimalString($data['targetPriceAmount'] ?? null, $currencyCode)?->minorAmount,
            'currency_code' => $currencyCode,
        ]);
    }

    public function uploadPhoto(Item $item, UploadedFile $file, int $sortOrder): ItemPhoto
    {
        return DB::transaction(function () use ($item, $file, $sortOrder): ItemPhoto {
            $asset = $this->mediaAssets->putUploadedFile(
                self::PHOTO_DISK,
                'commerce/inventory/item-photos/'.$item->id,
                $file,
            );

            return ItemPhoto::query()->create([
                'item_id' => $item->id,
                'media_asset_id' => $asset->id,
                'sort_order' => $sortOrder,
            ]);
        });
    }

    public function deletePhoto(ItemPhoto $photo): void
    {
        DB::transaction(function () use ($photo): void {
            $asset = $photo->mediaAsset;
            $photo->delete();
            $this->mediaAssets->delete($asset);
        });
    }

    public function setPhotoSelectedForListing(ItemPhoto $photo, bool $selectedForListing): void
    {
        $photo->update(['selected_for_listing' => $selectedForListing]);
    }

    public function deleteUnselectedPhotos(Item $item): int
    {
        $deleted = 0;

        $item->loadMissing('photos.mediaAsset');

        foreach ($item->photos->reject(fn (ItemPhoto $photo): bool => $photo->selected_for_listing) as $photo) {
            $this->deletePhoto($photo);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Delete cleaned alternates that are not currently selected for listings.
     * The original stays because it is the source image for future operations.
     */
    public function deleteUnselectedCleanedAssets(ItemPhoto $photo): int
    {
        $photo->loadMissing('cleanedAssets', 'selectedCleanedAsset', 'cleanedAsset');

        $selectedCleanedAsset = $photo->use_cleaned_photo ? $photo->activeCleanedAsset() : null;
        $selectedCleanedAssetId = $selectedCleanedAsset?->id;
        $deleted = 0;

        foreach ($photo->cleanedAssets as $cleanedAsset) {
            if ($selectedCleanedAssetId !== null && $cleanedAsset->id === $selectedCleanedAssetId) {
                continue;
            }

            $cleanedAsset->loadMissing('derivatives');
            $this->mediaAssets->delete($cleanedAsset);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Run background removal on this photo's original asset, creating or
     * replacing its `background_removed` derivative. The original asset is
     * never modified. The caller passes the owning company so the service
     * never reaches back through the photo's item relation.
     */
    public function cleanPhoto(ItemPhoto $photo, int $companyId): MediaAsset
    {
        return $this->photoCleanup->clean($photo->mediaAsset, $companyId);
    }

    /**
     * Switch a photo between its original and cleaned derivative for
     * marketplace listings. Reversible: toggling does not delete either
     * asset.
     */
    public function setUseCleanedPhoto(ItemPhoto $photo, bool $useCleanedPhoto, ?MediaAsset $cleanedAsset = null): void
    {
        $photo->update([
            'use_cleaned_photo' => $useCleanedPhoto,
            'selected_cleaned_asset_id' => $cleanedAsset?->id ?? $photo->selected_cleaned_asset_id,
        ]);
    }
}
