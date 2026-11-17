<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('climate_daily_summaries')) {
            return;
        }

        if (! Schema::hasColumn('climate_daily_summaries', 'relative_humidity_mean')) {
            Schema::table('climate_daily_summaries', function (Blueprint $table) {
                $table->unsignedSmallInteger('relative_humidity_mean')->nullable();
            });
        }

        if (! Schema::hasColumn('climate_daily_summaries', 'pm2_5_mean_ug_m3')) {
            Schema::table('climate_daily_summaries', function (Blueprint $table) {
                $table->decimal('pm2_5_mean_ug_m3', 7, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('climate_daily_summaries', 'us_aqi_mean')) {
            Schema::table('climate_daily_summaries', function (Blueprint $table) {
                $table->decimal('us_aqi_mean', 7, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Sengaja tidak menghapus kolom karena kolom-kolom ini mungkin
        // sudah ada dan berisi data sebelum migrasi ini dijalankan.
    }
};
