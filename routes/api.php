<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\PayrollController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Rute Publik (tidak perlu login)
Route::post('/login', [AuthController::class, 'login']);

// Grup Rute yang Membutuhkan Login (Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    
    // Rute Umum untuk semua user yang login
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user/change-password', [AuthController::class, 'changePassword']);

    // Grup Rute Khusus Admin
    Route::middleware('can:admin')->prefix('admin')->group(function() {

        // Manajemen Karyawan
        Route::post('/create-employee', [AuthController::class, 'createEmployee']);
        Route::get('/employees', [AdminController::class, 'getAllEmployees']);
        Route::delete('/employees/{id}', [AdminController::class, 'deleteEmployee']);
        
        // Dashboard & Approval
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::put('/leave-requests/{id}/approve', [AdminController::class, 'approveLeave']);
        Route::put('/leave-requests/{id}/reject', [AdminController::class, 'rejectLeave']);

        // Riwayat & Gaji
        Route::get('/attendance-history/{userId}', [AttendanceController::class, 'historyForAdmin']);
        Route::post('/payslip/generate/{userId}/{year}/{month}', [PayrollController::class, 'generate']);
        Route::get('/payslip/{userId}/{year}/{month}', [PayrollController::class, 'showForAdmin']);
        Route::get('/employee-history/{userId}', [AdminController::class, 'getEmployeeHistory']);
    });

    // Grup Rute Khusus Karyawan
    Route::prefix('karyawan')->group(function() {
        // Absensi
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/attendance-history', [AttendanceController::class, 'history']);
        Route::get('/history', [AttendanceController::class, 'history']);
        Route::get('/today-status', [AttendanceController::class, 'getTodayStatus']);

        // Pengajuan Cuti/Izin
        Route::post('/leave-request', [LeaveRequestController::class, 'store']);

        // Slip Gaji final (yang sudah di-generate)
        Route::get('/payslip/{year}/{month}', [PayrollController::class, 'showForEmployee']);

        // Slip Gaji live calculation
        Route::get('/payslip-live/{year}/{month}', [AttendanceController::class, 'calculateLivePayslip']);
    });
});
