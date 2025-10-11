<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

public function up(): void
{
    Schema::create('leave_requests', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        $table->enum('type', ['izin', 'cuti']);
        $table->text('reason');
        $table->date('start_date');
        $table->date('end_date');
        $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending'); // <-- KOLOM YANG HILANG
        $table->string('file_proof')->nullable();
        $table->string('location')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
