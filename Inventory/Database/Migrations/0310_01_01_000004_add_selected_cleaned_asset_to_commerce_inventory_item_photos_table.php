<?php

use App\Base\Media\Models\MediaAsset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
            $table->foreignId('selected_cleaned_asset_id')
                ->nullable()
                ->after('media_asset_id');

            $table->foreign('selected_cleaned_asset_id', 'commerce_item_photos_selected_cleaned_asset_fk')
                ->references('id')
                ->on('base_media_assets')
                ->nullOnDelete();
        });

        DB::table('commerce_inventory_item_photos')
            ->where('use_cleaned_photo', true)
            ->orderBy('id')
            ->each(function (object $photo): void {
                $selectedAssetId = DB::table('base_media_assets')
                    ->where('parent_id', $photo->media_asset_id)
                    ->where('kind', MediaAsset::KIND_BACKGROUND_REMOVED)
                    ->orderByDesc('id')
                    ->value('id');

                if ($selectedAssetId !== null) {
                    DB::table('commerce_inventory_item_photos')
                        ->where('id', $photo->id)
                        ->update(['selected_cleaned_asset_id' => $selectedAssetId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
            $table->dropForeign('commerce_item_photos_selected_cleaned_asset_fk');
            $table->dropColumn('selected_cleaned_asset_id');
        });
    }
};
