<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('session_key', 64)->index();
            $table->string('user_hash', 64)->nullable()->index();
            $table->string('query');
            $table->string('query_norm')->index();
            $table->string('model_version', 64)->nullable();
            $table->string('mode', 32)->nullable();
            $table->boolean('is_milk_query')->nullable();
            $table->string('source', 16)->default('search');
            $table->unsignedSmallInteger('page')->default(1);
            $table->timestamps();
        });

        Schema::create('search_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_session_id')->constrained('search_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('page')->default(1);
            $table->timestamps();

            $table->unique(['search_session_id', 'product_id']);
        });

        Schema::create('search_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_session_id')->constrained('search_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->unsignedSmallInteger('position')->nullable();
            $table->timestamps();

            $table->index(['search_session_id', 'product_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_events');
        Schema::dropIfExists('search_impressions');
        Schema::dropIfExists('search_sessions');
    }
};
