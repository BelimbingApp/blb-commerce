<?php

namespace App\Modules\Commerce\Inventory\Models;

use App\Base\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Pivot row that orders an item's photos and points at the underlying
 * {@see MediaAsset}. File metadata (filename, disk, storage_key, mime_type,
 * file_size) lives on the asset; this row only owns the inventory-side
 * relationship, ordering, and which version (original or cleaned) is used
 * for marketplace listings.
 *
 * @property int $id
 * @property int $item_id
 * @property int $media_asset_id
 * @property int|null $selected_cleaned_asset_id
 * @property int $sort_order
 * @property bool $selected_for_listing
 * @property bool $use_cleaned_photo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 * @property-read MediaAsset $mediaAsset
 * @property-read MediaAsset|null $cleanedAsset
 * @property-read Collection<int, MediaAsset> $cleanedAssets
 * @property-read MediaAsset|null $selectedCleanedAsset
 */
class ItemPhoto extends Model
{
    protected $table = 'commerce_inventory_item_photos';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'item_id',
        'media_asset_id',
        'selected_cleaned_asset_id',
        'sort_order',
        'selected_for_listing',
        'use_cleaned_photo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_for_listing' => 'boolean',
            'use_cleaned_photo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function selectedCleanedAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'selected_cleaned_asset_id');
    }

    /**
     * The latest `background_removed` derivative of this photo's media asset,
     * if a photo-cleanup run has produced one. Multiple providers can each keep
     * a derivative; this remains the fallback for legacy callers.
     *
     * @return HasOne<MediaAsset, $this>
     */
    public function cleanedAsset(): HasOne
    {
        return $this->hasOne(MediaAsset::class, 'parent_id', 'media_asset_id')
            ->where('kind', MediaAsset::KIND_BACKGROUND_REMOVED)
            ->latestOfMany();
    }

    /**
     * Every provider-specific cleaned derivative for this photo.
     *
     * @return HasMany<MediaAsset, $this>
     */
    public function cleanedAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'parent_id', 'media_asset_id')
            ->where('kind', MediaAsset::KIND_BACKGROUND_REMOVED)
            ->orderBy('id');
    }

    /**
     * The asset to render/publish: the cleaned derivative when the operator
     * has accepted it, otherwise the original. Falls back to the original if
     * accepted but the derivative is missing, so this never returns null
     * while mediaAsset exists.
     */
    public function displayAsset(): ?MediaAsset
    {
        if ($this->use_cleaned_photo) {
            $cleaned = $this->activeCleanedAsset();

            if ($cleaned instanceof MediaAsset) {
                return $cleaned;
            }
        }

        return $this->mediaAsset;
    }

    public function activeCleanedAsset(): ?MediaAsset
    {
        $selected = $this->selectedCleanedAsset;

        if ($selected instanceof MediaAsset
            && $selected->parent_id === $this->media_asset_id
            && $selected->kind === MediaAsset::KIND_BACKGROUND_REMOVED) {
            return $selected;
        }

        return $this->cleanedAsset;
    }

    public function cleanedAssetForProvider(string $providerKey): ?MediaAsset
    {
        if ($this->relationLoaded('cleanedAssets')) {
            $asset = $this->cleanedAssets
                ->first(fn (MediaAsset $asset): bool => data_get($asset->metadata, 'provider') === $providerKey);

            return $asset instanceof MediaAsset ? $asset : null;
        }

        $asset = $this->cleanedAssets()
            ->get()
            ->first(fn (MediaAsset $asset): bool => data_get($asset->metadata, 'provider') === $providerKey);

        return $asset instanceof MediaAsset ? $asset : null;
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->item_id !== null ? ['name' => 'item', 'id' => (int) $this->item_id] : null;
    }
}
