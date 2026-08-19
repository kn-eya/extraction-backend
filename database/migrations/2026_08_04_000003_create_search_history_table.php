<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type_recherche', ['Entreprise', 'Personne']);
            $table->string('mot_cle')->nullable();
            $table->string('secteur')->nullable();
            $table->string('ville')->nullable();
            // Peut aussi contenir un nom de region (resolu en code via BelgiumGeography)
            $table->string('province')->nullable();
            $table->string('code_postal')->nullable();
            $table->integer('rayon')->nullable();
            $table->timestamp('date')->useCurrent();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_history');
    }
};
