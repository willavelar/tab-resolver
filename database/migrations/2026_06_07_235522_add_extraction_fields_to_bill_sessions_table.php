<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('image_path');
            $table->decimal('subtotal', 10, 2)->nullable()->after('status');
            $table->decimal('service_charge', 10, 2)->nullable()->after('subtotal');
            $table->decimal('total', 10, 2)->nullable()->after('service_charge');
            $table->json('raw_extraction')->nullable()->after('total');
            $table->timestamp('processed_at')->nullable()->after('raw_extraction');
            $table->text('failure_reason')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'subtotal', 'service_charge', 'total',
                'raw_extraction', 'processed_at', 'failure_reason',
            ]);
        });
    }
};
