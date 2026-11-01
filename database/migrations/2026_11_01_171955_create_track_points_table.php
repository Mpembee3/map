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
        Schema::create('track_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->dateTime('recorded_at')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->double('altitude_hae')->nullable();
            $table->double('jam_indicator')->nullable();
            $table->double('hdop')->nullable();
            $table->unsignedTinyInteger('satellites_used')->nullable();
            $table->double('noise')->nullable();
            $table->boolean('has_fix')->default(false);
            $table->boolean('is_suspect')->default(false);

            $table->unique(['flight_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('track_points');
    }
};
