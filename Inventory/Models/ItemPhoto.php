<?php
namespace App\Modules\Commerce\Inventory\Models;

use App\Base\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $sort_order
 * @property bool $use_cleaned_photo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 * @property-read MediaAsset $mediaAsset
 * @property-read MediaAsset|null $cleanedAsset
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
        'sort_order',
        'use_cleaned_photo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * The `background_removed` derivative of this photo's media asset, if a
     * photo-cleanup run has produced one.
     *
     * @return HasOne<MediaAsset, $this>
     */
    public function cleanedAsset(): HasOne
    {
        return $this->hasOne(MediaAsset::class, 'parent_id', 'media_asset_id')
            ->where('kind', MediaAsset::KIND_BACKGROUND_REMOVED);
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
            $cleaned = $this->cleanedAsset;

            if ($cleaned instanceof MediaAsset) {
                return $cleaned;
            }
        }

        return $this->mediaAsset;
    }
}
