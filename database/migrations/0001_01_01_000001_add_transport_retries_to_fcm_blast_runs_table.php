<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fcm_blast_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('transport_retries')->default(0)->after('throttled');
        });
    }

    public function down(): void
    {
        Schema::table('fcm_blast_runs', function (Blueprint $table) {
            $table->dropColumn('transport_retries');
        });
    }
};
