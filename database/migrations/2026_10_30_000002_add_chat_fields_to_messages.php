<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('body');
            $table->timestamp('notified_at')->nullable()->after('read_at'); // emailed to the recipient while unread
            $table->index(['client_id', 'id']);
            $table->index(['read_at', 'notified_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'id']);
            $table->dropIndex(['read_at', 'notified_at', 'created_at']);
            $table->dropColumn(['photos', 'notified_at']);
        });
    }
};
