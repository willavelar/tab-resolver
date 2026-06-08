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
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->decimal('service_charge_percentage', 5, 2)->nullable()->after('service_charge');
            $table->json('clarifications')->nullable()->after('raw_extraction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn(['service_charge_percentage', 'clarifications']);
        });
    }
};
