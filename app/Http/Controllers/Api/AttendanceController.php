<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{

    public function checkIn(Request $request)
    {
        $request->validate([
            'latitude' => 'required|string',
            'longitude' => 'required|string',
        ]);

        $user = Auth::user();

        // VALIDASI BARU: Cek apakah ada cuti atau izin yang disetujui hari ini
        $existingLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->first();

        if ($existingLeave) {
            return response()->json(['message' => 'Anda tidak bisa absen karena sedang dalam masa ' . $existingLeave->type . ' yang disetujui.'], 400);
        }

        // Cek absensi ganda
        $todayAttendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', today())
            ->first();

        if ($todayAttendance) {
            return response()->json(['message' => 'Anda sudah melakukan absen masuk hari ini.'], 400);
        }

        // Buat record absensi baru
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'check_in_time' => now(),
            'check_in_location' => $request->latitude . ',' . $request->longitude,
            'date' => today(),
        ]);

        return response()->json(['message' => 'Absen masuk berhasil.', 'data' => $attendance], 201);
    }
    public function checkOut(Request $request)
    {
        $request->validate([
            'latitude' => 'required|string',
            'longitude' => 'required|string',
        ]);

        $user = Auth::user();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', today())
            ->whereNull('check_out_time')
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'Anda belum melakukan absen masuk atau sudah absen pulang.'], 404);
        }

        $attendance->update([
            'check_out_time' => now(),
            'check_out_location' => $request->latitude . ',' . $request->longitude,
        ]);

        return response()->json(['message' => 'Absen pulang berhasil.', 'data' => $attendance]);
    }


    public function history()
{
    $user = Auth::user();

    // data absen ambil dari id ny oke
    $attendances = Attendance::where('user_id', $user->id)->get();

    //data cuti/izin nya hm
    $leaveRequests = LeaveRequest::where('user_id', $user->id)->get();

    $combinedData = $attendances->concat($leaveRequests);

    // ini buat urutan berdasarkan tanggalnya, ingettttttttttt ka
    $sortedData = $combinedData->sortByDesc('created_at');

    return response()->json($sortedData->values()->all());
}
    public function historyForAdmin($userId)
    {
        $history = Attendance::where('user_id', $userId)
                        ->orderBy('date', 'desc')
                        ->get();
        return response()->json($history);
    }
     public function getTodayStatus()
    {
        $user = Auth::user();
        
        $leave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->first();
        if ($leave) {
            return response()->json(['status' => $leave->type]); // "izin" atau "cuti"
        }

        $attendance = Attendance::where('user_id', $user->id)->whereDate('date', today())->first();
        if ($attendance) {
            return response()->json(['status' => ($attendance->check_out_time ? 'checked_out' : 'checked_in')]);
        }

        return response()->json(['status' => 'none']); 
    }
}