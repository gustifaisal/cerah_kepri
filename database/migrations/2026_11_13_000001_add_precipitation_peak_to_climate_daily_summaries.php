<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('climate_daily_summaries') || Schema::hasColumn('climate_daily_summaries', 'precipitation_max_hourly_mm')) {
            return;
        }

        Schema::table('climate_daily_summaries', function (Blueprint $table) {
            // Curah hujan per jam tertinggi pada hari itu (puncak hujan), dalam mm.
            $table->decimal('precipitation_max_hourly_mm', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('climate_daily_summaries', 'precipitation_max_hourly_mm')) {
            Schema::table('climate_daily_summaries', function (Blueprint $table) {
                $table->dropColumn('precipitation_max_hourly_mm');
            });
        }
    }
};