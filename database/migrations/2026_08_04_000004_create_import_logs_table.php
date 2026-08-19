<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            // Generalement null : alimente par le pipeline automatique
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Generalement null : pipeline independant des recherches admin
            $table->foreignId('search_history_id')->nullable()->constrained('search_history')->nullOnDelete();
            // Rempli quand l'import vient du scheduler automatique (02h)
            $table->foreignId('extraction_zone_id')->nullable()->constrained('extraction_zones')->nullOnDelete();
            $table->enum('type_import', ['Entreprise', 'Personne']);
            // Compte uniquement les nouvelles lignes inserees
            $table->integer('nombre_resultats')->default(0);
            $table->timestamp('date_import')->useCurrent();
            $table->timestamp('termine_le')->nullable();
            $table->enum('statut', ['en_cours', 'termine', 'echec'])->default('en_cours');
            $table->text('message_erreur')->nullable();
            $table->timestamps();

            $table->index('statut');
            $table->index('extraction_zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
