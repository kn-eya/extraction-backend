<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_history', function (Blueprint $table) {
            $table->boolean('has_website')->default(false)->after('rayon');
            $table->boolean('has_phone')->default(false)->after('has_website');
            $table->boolean('has_email')->default(false)->after('has_phone');
            $table->boolean('has_social')->default(false)->after('has_email');
        });
    }

    public function down(): void
    {
        Schema::table('search_history', function (Blueprint $table) {
            $table->dropColumn(['has_website', 'has_phone', 'has_email', 'has_social']);
        });
    }
};