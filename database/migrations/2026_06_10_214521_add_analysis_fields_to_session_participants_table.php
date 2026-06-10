<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('session_participants', function (Blueprint $table) {
            $table->text('transcript')->nullable()->after('audio_duration');
            $table->decimal('amount_due', 10, 2)->nullable()->after('transcript');
            $table->json('breakdown')->nullable()->after('amount_due');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_participants', function (Blueprint $table) {
            $table->dropColumn(['transcript', 'amount_due', 'breakdown']);
        });
    }
};
