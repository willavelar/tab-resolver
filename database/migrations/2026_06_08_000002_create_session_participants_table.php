<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_participants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bill_session_id')
                ->constrained('bill_sessions')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('text', 256)->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedSmallInteger('audio_duration')->nullable();
            $table->timestamps();

            $table->unique(['bill_session_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_participants');
    }
};
