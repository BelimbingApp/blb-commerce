<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_inventory_item_photos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('item_id')
                ->constrained('commerce_inventory_items')
                ->cascadeOnDelete();

            $table->foreignId('media_asset_id')
                ->constrained('base_media_assets')
                ->restrictOnDelete();

            $table->foreignId('selected_cleaned_asset_id')
                ->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('use_cleaned_photo')->default(false);

            $table->timestamps();

            $table->foreign('selected_cleaned_asset_id', 'commerce_item_photos_selected_cleaned_asset_fk')
                ->references('id')
                ->on('base_media_assets')
                ->nullOnDelete();
            $table->index(['item_id', 'sort_order']);
            $table->unique('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_item_photos');
    }
};
