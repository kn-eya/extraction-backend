<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('search_history_id')->nullable()->constrained('search_history')->nullOnDelete();
            $table->foreignId('import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();
            $table->foreignId('export_id')->nullable()->constrained('exports')->nullOnDelete();
            // connexion / recherche / import / export / erreur
            $table->string('action');
            $table->text('description')->nullable();
            $table->timestamp('date')->useCurrent();
            $table->timestamps();

            $table->index('user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
