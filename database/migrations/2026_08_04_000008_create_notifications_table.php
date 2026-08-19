<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Permet le filtrage/l'affichage differencie cote interface (icone, priorite)
            $table->enum('type', ['import_termine', 'nouvelle_recherche', 'base_maj', 'nouveaux_contacts'])->nullable();
            $table->string('titre');
            $table->text('message')->nullable();
            $table->boolean('lu')->default(false);
            $table->timestamp('date')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'lu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
