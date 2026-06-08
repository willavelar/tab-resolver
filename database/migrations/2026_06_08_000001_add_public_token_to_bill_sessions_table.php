<?php

use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('image_path');
        });

        Session::whereNull('public_token')->each(function (Session $session) {
            $session->update(['public_token' => Str::random(32)]);
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
