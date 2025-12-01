<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dateTime('check_in_time')->nullable()->change();
            $table->string('check_in_location')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dateTime('check_in_time')->nullable(false)->change();
            $table->string('check_in_location')->nullable(false)->change();
        });
    }
};