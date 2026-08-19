<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_geocode_source_check');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_geocode_source_check CHECK (geocode_source IN ('overpass', 'nominatim', 'photon', 'manuel'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_geocode_source_check');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_geocode_source_check CHECK (geocode_source IN ('overpass', 'nominatim', 'manuel'))");
    }
};