<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('search_history_id')->nullable()->constrained('search_history')->nullOnDelete();
            $table->enum('type_export', ['Entreprise', 'Personne']);
            $table->enum('format', ['Excel', 'CSV', 'JSON', 'XML', 'SQL']);
            $table->integer('nombre_lignes')->default(0);
            $table->timestamp('date_export')->useCurrent();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
