<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commerce_inventory_item_photos', 'selected_cleaned_asset_id')) {
            Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
                $table->foreignId('selected_cleaned_asset_id')
                    ->nullable()
                    ->after('media_asset_id');

                $table->foreign('selected_cleaned_asset_id', 'commerce_item_photos_selected_cleaned_asset_fk')
                    ->references('id')
                    ->on('base_media_assets')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('commerce_inventory_item_photos', 'selected_for_listing')) {
            Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
                $table->boolean('selected_for_listing')
                    ->default(true)
                    ->after('sort_order');
            });
        }

        if (! Schema::hasColumn('commerce_inventory_item_photos', 'use_cleaned_photo')) {
            Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
                $table->boolean('use_cleaned_photo')
                    ->default(false)
                    ->after('selected_for_listing');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commerce_inventory_item_photos', 'selected_cleaned_asset_id')) {
            Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
                $table->dropForeign('commerce_item_photos_selected_cleaned_asset_fk');
                $table->dropColumn('selected_cleaned_asset_id');
            });
        }

        $columns = collect(['selected_for_listing', 'use_cleaned_photo'])
            ->filter(fn (string $column): bool => Schema::hasColumn('commerce_inventory_item_photos', $column))
            ->all();

        if ($columns !== []) {
            Schema::table('commerce_inventory_item_photos', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
