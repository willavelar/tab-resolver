<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->renameColumn('model', 'receipt_model');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->string('audio_model')->nullable()->after('receipt_model');
            $table->string('provider')->default('openai')->change();
        });

        // The stored key was Anthropic-specific and is invalid for OpenAI:
        // flip the singleton to openai and force re-entry of the key.
        DB::table('integrations')->update([
            'provider' => 'openai',
            'api_key' => null,
            'audio_model' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('audio_model');
            $table->string('provider')->default('anthropic')->change();
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->renameColumn('receipt_model', 'model');
        });
    }
};
