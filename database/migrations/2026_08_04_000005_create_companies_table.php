<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // --- Identite / coordonnees ---
            $table->string('nom');
            $table->string('categorie')->nullable();
            $table->text('adresse')->nullable();
            $table->string('ville')->nullable();
            $table->string('province')->nullable();
            // Calculee automatiquement depuis province (evenement saving du modele), jamais saisie manuellement
            $table->string('region')->nullable();
            $table->string('code_postal')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('site_web')->nullable();

            // --- Reputation ---
            // Null tant que source = OSM uniquement
            $table->decimal('note', 3, 2)->nullable()->default(0);
            $table->integer('nb_avis')->nullable()->default(0);

            // --- Position ---
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->jsonb('horaires')->nullable();

            // --- Sources externes : Google Places (inactif tant que non branche) ---
            $table->string('google_url', 500)->nullable()->unique();

            // --- Sources externes : OSM / Overpass (cle de deduplication active) ---
            $table->string('osm_id')->nullable();
            // node / way / relation - l'osm_id seul n'est pas unique sans son type
            $table->string('osm_type', 10)->nullable();

            // --- Sources externes : Nominatim (reverse geocoding, en fallback des tags OSM) ---
            $table->unsignedBigInteger('nominatim_place_id')->nullable()->unique();
            $table->text('adresse_normalisee')->nullable();
            $table->string('nominatim_classe')->nullable();
            $table->string('nominatim_type')->nullable();
            $table->enum('geocode_source', ['overpass', 'nominatim', 'manuel'])->nullable();
            $table->timestamp('geocode_le')->nullable();

            // --- Enrichissement (scan automatique du site web) ---
            $table->jsonb('enrichissement')->nullable();
            $table->timestamp('enrichi_le')->nullable();

            // --- Synchronisation moteur de recherche ---
            $table->timestamp('es_synchronise_le')->nullable();

            // --- Reserve pour un enrichissement futur (non alimente en V1) ---
            $table->string('taille_entreprise')->nullable();

            // --- Suivi pipeline ---
            $table->timestamp('date_import')->useCurrent();
            $table->timestamp('derniere_verification')->nullable();
            $table->string('statut')->default('actif');

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();

            $table->timestamps();

            // --- Index de recherche (fallback leger, la recherche principale passe par Elasticsearch) ---
            $table->index('ville');
            $table->index('province');
            $table->index('region');
            $table->index('categorie');
            $table->index('statut');
            $table->index(['province', 'categorie']);
            $table->index(['latitude', 'longitude']);

            // Dedup exacte : un meme objet OSM (id + type) ne doit exister qu'une fois
            $table->unique(['osm_id', 'osm_type']);
        });

        // Index full-text (GIN + trigram) sur le nom, pour la recherche admin en fallback
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX companies_nom_trgm_idx ON companies USING GIN (nom gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
