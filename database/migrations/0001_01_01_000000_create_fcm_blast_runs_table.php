<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_blast_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('total');
            $table->unsignedInteger('workers');
            $table->unsignedInteger('rate_cap_per_sec');
            $table->unsignedBigInteger('sent')->default(0);
            $table->unsignedBigInteger('ok')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->unsignedBigInteger('invalid')->default(0);
            $table->unsignedBigInteger('throttled')->default(0);
            $table->unsignedBigInteger('latency_sum_ms')->default(0);
            $table->boolean('validate_only')->default(false);
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_blast_runs');
    }
};
