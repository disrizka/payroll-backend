<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon; // Jangan lupa import Carbon

class AttendanceController extends Controller
{
    /**
     * Menangani proses check-in karyawan.
     */
    public function checkIn(Request $request)
    {
        $request->validate([
            'latitude' => 'required|string',
            'longitude' => 'required|string',
        ]);

        $user = Auth::user();

        // Cek apakah ada cuti atau izin yang disetujui hari ini
        $existingLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->first();
        if ($existingLeave) {
            return response()->json(['message' => 'Anda tidak bisa absen karena sedang ' . $existingLeave->type], 400);
        }

        // Cek absensi ganda
        $todayAttendance = Attendance::where('user_id', $user->id)->whereDate('date', today())->first();
        if ($todayAttendance) {
            return response()->json(['message' => 'Anda sudah melakukan absen masuk hari ini.'], 400);
        }

        // Tentukan status check-in
        $jamMasuk = now();
        $statusCheckIn = ($jamMasuk->format('H:i:s') > '07:30:00') ? 'Telat' : 'Tepat Waktu';

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'check_in_time' => $jamMasuk,
            'check_in_location' => $request->latitude . ',' . $request->longitude,
            'date' => today(),
            'status_check_in' => $statusCheckIn, // Simpan status
        ]);

        return response()->json(['message' => 'Absen masuk berhasil.', 'data' => $attendance], 201);
    }

    /**
     * Menangani proses check-out karyawan.
     */
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

        // Tentukan status check-out
        $jamPulang = now();
        $statusCheckOut = 'Normal';
        if ($jamPulang->format('H:i:s') < '17:00:00') {
            $statusCheckOut = 'Pulang Awal';
        } elseif ($jamPulang->hour >= 18) { // Overtime jika pulang lewat dari jam 6 sore
            $statusCheckOut = 'Overtime';
        }

        $attendance->update([
            'check_out_time' => $jamPulang,
            'check_out_location' => $request->latitude . ',' . $request->longitude,
            'status_check_out' => $statusCheckOut, // Simpan status
        ]);

        return response()->json(['message' => 'Absen pulang berhasil.', 'data' => $attendance]);
    }

    /**
     * Menampilkan riwayat gabungan (absensi & izin/cuti) untuk karyawan.
     */
    public function history()
    {
        $user = Auth::user();
        $attendances = Attendance::where('user_id', $user->id)->get();
        $leaveRequests = LeaveRequest::where('user_id', $user->id)->get();
        $combinedData = $attendances->concat($leaveRequests);
        $sortedData = $combinedData->sortByDesc('created_at');
        return response()->json($sortedData->values()->all());
    }

    /**
     * Menampilkan riwayat absensi untuk user tertentu (dilihat oleh admin).
     */
    public function historyForAdmin($userId)
    {
        $history = Attendance::where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->get();
        return response()->json($history);
    }

    /**
     * Mengecek status aktivitas karyawan hari ini.
     */
    public function getTodayStatus()
    {
        $user = Auth::user();
        
        $leave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->first();
        if ($leave) {
            return response()->json(['status' => $leave->type]); // "izin" atau "cuti"
        }

        $attendance = Attendance::where('user_id', $user->id)->whereDate('date', today())->first();
        if ($attendance) {
            return response()->json(['status' => ($attendance->check_out_time ? 'checked_out' : 'checked_in')]);
        }

        return response()->json(['status' => 'none']); // Belum ada aktivitas
    }
}