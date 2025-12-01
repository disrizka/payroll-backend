<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\PayrollController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user/change-password', [AuthController::class, 'changePassword']);

    // ✅ ADMIN ROUTES
    Route::middleware('can:admin')->prefix('admin')->group(function() {
        
        Route::post('/create-employee', [AuthController::class, 'createEmployee']);
        Route::get('/employees', [AdminController::class, 'getAllEmployees']);
        Route::delete('/employees/{id}', [AdminController::class, 'deleteEmployee']);
        
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        
        // ✅ LEAVE REQUEST MANAGEMENT (Updated)
        Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
        Route::get('/leave-requests/pending', [LeaveRequestController::class, 'getPending']);
        Route::put('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
        Route::put('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);


        Route::get('/attendance-history/{userId}', [AttendanceController::class, 'historyForAdmin']);
        Route::get('/employee-history/{userId}', [AdminController::class, 'getEmployeeHistory']);
        
        Route::post('/payslip/generate/{userId}/{year}/{month}', [PayrollController::class, 'generate']);
        Route::get('/payslip/{userId}/{year}/{month}', [PayrollController::class, 'showForAdmin']);
        
        Route::get('/payslip-live/{userId}/{year}/{month}', [AttendanceController::class, 'calculateLivePayslipForAdmin']);
    });

    // ✅ EMPLOYEE ROUTES
    Route::prefix('karyawan')->group(function() {
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/attendance-history', [AttendanceController::class, 'history']);
        Route::get('/history', [AttendanceController::class, 'history']);
        Route::get('/today-status', [AttendanceController::class, 'getTodayStatus']);

        Route::post('/leave-request', [LeaveRequestController::class, 'store']);
        Route::get('/my-leave-requests', [LeaveRequestController::class, 'myLeaveRequests']);

        Route::get('/payslip-live/{year}/{month}', [AttendanceController::class, 'calculateLivePayslip']);
        Route::get('/payslip/{year}/{month}', [PayrollController::class, 'showForEmployee']);


        Route::get('/yearly-stats/{year}', [AttendanceController::class, 'getYearlyStats']);
        Route::get('/monthly-stats', [AttendanceController::class, 'getMonthlyStats']);
            });
});