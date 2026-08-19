<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_zones', function (Blueprint $table) {
            $table->id();
            $table->string('province');
            // Correspond a un tag OSM (ex: amenity=restaurant)
            $table->string('secteur');
            $table->timestamp('derniere_extraction')->nullable();
            $table->integer('frequence_jours')->default(7);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['province', 'secteur']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_zones');
    }
};
