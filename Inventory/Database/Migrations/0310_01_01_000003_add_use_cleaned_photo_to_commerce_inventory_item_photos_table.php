<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
            $table->boolean('use_cleaned_photo')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_inventory_item_photos', function (Blueprint $table): void {
            $table->dropColumn('use_cleaned_photo');
        });
    }
};
