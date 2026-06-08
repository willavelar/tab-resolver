<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL won't drop a composite unique index when it is the only index
        // covering the FK column. Add a plain index first so MySQL can reassign
        // the FK backing index before we drop the composite unique.
        Schema::table('session_participants', function (Blueprint $table) {
            $table->index('bill_session_id');
        });

        Schema::table('session_participants', function (Blueprint $table) {
            $table->dropUnique(['bill_session_id', 'name']);
        });

        Schema::table('session_participants', function (Blueprint $table) {
            $table->string('submitter_token')->nullable()->after('name');
            $table->string('ip_address')->nullable()->after('audio_duration');
            $table->text('user_agent')->nullable()->after('ip_address');

            $table->unique(['bill_session_id', 'submitter_token']);
        });
    }

    public function down(): void
    {
        Schema::table('session_participants', function (Blueprint $table) {
            $table->dropUnique(['bill_session_id', 'submitter_token']);
            $table->dropColumn(['submitter_token', 'ip_address', 'user_agent']);
        });

        Schema::table('session_participants', function (Blueprint $table) {
            $table->unique(['bill_session_id', 'name']);
        });

        // Remove the plain index added to work around the MySQL FK/unique-drop constraint.
        Schema::table('session_participants', function (Blueprint $table) {
            $table->dropIndex(['bill_session_id']);
        });
    }
};
