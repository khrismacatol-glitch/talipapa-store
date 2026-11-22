<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['category_id', 'is_available', 'stock'], 'products_category_available_stock_index');
            $table->index(['store_profile_id', 'is_available', 'stock'], 'products_store_available_stock_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_available_stock_index');
            $table->dropIndex('products_store_available_stock_index');
        });
    }
};
