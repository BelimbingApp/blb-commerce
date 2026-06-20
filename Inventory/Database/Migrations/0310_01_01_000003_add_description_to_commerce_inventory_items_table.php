<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        if (Schema::hasColumn('commerce_inventory_items', 'description')) {
            return;
        }

        Schema::table('commerce_inventory_items', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        // No-op by design: fresh databases already get this column from the
        // incubating create migration, so rollback must not remove it there.
    }
};
