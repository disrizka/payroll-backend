<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::table('attendances', function (Blueprint $table) {
        $table->string('status_check_in')->nullable()->after('date');
        $table->string('status_check_out')->nullable()->after('status_check_in');
    });
}

public function down(): void
{
    Schema::table('attendances', function (Blueprint $table) {
        $table->dropColumn(['status_check_in', 'status_check_out']);
    });
}
};
