<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard()
    {
        $totalKaryawan = User::where('role', 'karyawan')->count();
        $pendingRequests = LeaveRequest::with('user:id,name,nik')
                            ->where('status', 'pending')
                            ->orderBy('created_at', 'desc')
                            ->get();

        return response()->json([
            'total_karyawan' => $totalKaryawan,
            'pending_requests' => $pendingRequests,
        ]);
    }
      public function getAllEmployees()
    {
        $employees = User::where('role', 'karyawan')->orderBy('name')->get();
        return response()->json($employees);
    }

    public function deleteEmployee($id)
    {
        $employee = User::find($id);

        if (!$employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        if ($employee->role !== 'karyawan') {
            return response()->json(['message' => 'Anda tidak bisa menghapus admin.'], 403);
        }

        $employee->delete();

        return response()->json(['message' => 'Karyawan berhasil dihapus.']);
    }
    public function approveLeave($id)
    {
        $leaveRequest = LeaveRequest::find($id);
        if (!$leaveRequest) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }
        $leaveRequest->status = 'approved';
        $leaveRequest->save();
        return response()->json(['message' => 'Pengajuan berhasil disetujui']);
    }

    public function rejectLeave($id)
    {
        $leaveRequest = LeaveRequest::find($id);
        if (!$leaveRequest) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }
        $leaveRequest->status = 'rejected';
        $leaveRequest->save();
        return response()->json(['message' => 'Pengajuan berhasil ditolak']);
    }


public function getEmployeeHistory($userId)
{
    $attendances = \App\Models\Attendance::where('user_id', $userId)->get();
    $leaveRequests = \App\Models\LeaveRequest::where('user_id', $userId)->get();
    $combinedData = $attendances->concat($leaveRequests);
    $sortedData = $combinedData->sortByDesc('created_at');

    return response()->json($sortedData->values()->all());
}
}