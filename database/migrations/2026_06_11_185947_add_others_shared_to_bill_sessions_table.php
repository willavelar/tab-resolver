<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->boolean('others_shared')->default(false)->after('food_shared');
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn('others_shared');
        });
    }
};
