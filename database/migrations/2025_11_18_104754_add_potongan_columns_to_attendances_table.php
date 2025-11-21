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
        Schema::table('attendances', function (Blueprint $table) {
            // Kolom yang sudah Anda definisikan: Potongan denda check-in
            if (!Schema::hasColumn('attendances', 'potongan_check_in')) {
                $table->integer('potongan_check_in')->default(0)->after('status_check_in');
            }
            
            // Kolom yang Anda sebutkan hilang: Potongan denda check-out
            if (!Schema::hasColumn('attendances', 'potongan_check_out')) {
                $table->integer('potongan_check_out')->default(0)->after('status_check_out');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'potongan_check_in')) {
                $table->dropColumn('potongan_check_in');
            }
            if (Schema::hasColumn('attendances', 'potongan_check_out')) {
                $table->dropColumn('potongan_check_out');
            }
        });
    }
};
