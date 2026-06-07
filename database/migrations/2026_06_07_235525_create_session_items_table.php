<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bill_session_id')
                ->constrained('bill_sessions')
                ->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 8, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_items');
    }
};
