<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_duplicates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();
            // osm_id_exact aujourd'hui ; fuzzy_nom_adresse prevu quand une 2e source
            // (ex: Google Places) sera branchee et n'aura pas de cle commune avec OSM
            $table->enum('methode_detection', ['osm_id_exact', 'fuzzy_nom_adresse'])->default('osm_id_exact');
            // Rempli uniquement pour une detection fuzzy (0.00 a 1.00)
            $table->decimal('score_similarite', 3, 2)->nullable();
            $table->timestamp('date')->useCurrent();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_duplicates');
    }
};
