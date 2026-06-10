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
            $table->boolean('food_shared')->default(true)->after('total');
            $table->string('analysis_status')->default('pending')->after('food_shared');
            $table->json('analysis_clarifications')->nullable()->after('analysis_status');
            $table->json('analysis_result')->nullable()->after('analysis_clarifications');
            $table->text('analysis_failure_reason')->nullable()->after('analysis_result');
            $table->timestamp('analyzed_at')->nullable()->after('analysis_failure_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'food_shared',
                'analysis_status',
                'analysis_clarifications',
                'analysis_result',
                'analysis_failure_reason',
                'analyzed_at',
            ]);
        });
    }
};
