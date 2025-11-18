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
   /**
 * Get employee history for admin
 */
public function historyForEmployee($userId)
{
    try {
        // Ambil data attendance
        $attendances = Attendance::where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'date' => $item->date,
                    'check_in_time' => $item->check_in_time,
                    'check_out_time' => $item->check_out_time,
                    'check_in_location' => $item->check_in_location,
                    'check_out_location' => $item->check_out_location,
                    'status_check_in' => $item->status_check_in,
                    'status_check_out' => $item->status_check_out,
                ];
            });

        // Ambil data leave requests
        $leaveRequests = LeaveRequest::where('user_id', $userId)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'start_date' => $item->start_date,
                    'end_date' => $item->end_date,
                    'reason' => $item->reason,
                    'status' => $item->status,
                    'location' => $item->location,
                    'file_proof' => $item->file_proof,
                ];
            });

        // Merge dan sort by date
        $history = $attendances->concat($leaveRequests);

        return response()->json($history);
    } catch (\Exception $e) {
        \Log::error('Error fetching employee history: ' . $e->getMessage());
        return response()->json([
            'message' => 'Gagal memuat riwayat karyawan',
            'error' => $e->getMessage()
        ], 500);
    }
}
    public function calculateLivePayslip($year, $month)
{
    try {
        $user = Auth::user();
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        
        // Jika user melihat bulan yang sudah lewat, hitung sampai akhir bulan tsb
        if ($startDate->isBefore(Carbon::now()->startOfMonth())) {
            $endDate = $startDate->copy()->endOfMonth();
        }

        // Aturan Gaji
        $gajiPokokHarian = 50000;
        $potonganTelat = 20000;  
        $tunjanganHarian = 25000;
        $pajakBulanan = 100000;

        $totalGajiPokok = 0;
        $totalTunjangan = 0;
        $totalPotonganHarian = 0;
        $detailPerHari = [];

        // Ambil data attendance
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn($item) => Carbon::parse($item->date)->toDateString());

        // Ambil data leave yang approved
        $leaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        // Loop per hari
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateStr = $date->toDateString();
            $attendance = $attendances->get($dateStr);
            
            // Cek apakah ada leave di tanggal ini
            $leave = $leaves->first(function($l) use ($date) {
                return $date->between(Carbon::parse($l->start_date), Carbon::parse($l->end_date));
            });

            $gajiPokok = 0;
            $tunjangan = 0;
            $potongan = 0;
            $status = 'alpha';
            $checkIn = '-';
            $checkOut = '-';

            if ($leave) {
                // Jika ada cuti/izin yang approved
                if ($leave->type === 'cuti') {
                    $gajiPokok = $gajiPokokHarian;
                    $tunjangan = 0;
                    $status = 'cuti';
                } else {
                    $gajiPokok = 0;
                    $tunjangan = 0;
                    $status = 'izin';
                }
            } elseif ($attendance) {
                // Ada absensi
                $checkIn = $attendance->check_in_time ? Carbon::parse($attendance->check_in_time)->format('H:i:s') : '-';
                $checkOut = $attendance->check_out_time ? Carbon::parse($attendance->check_out_time)->format('H:i:s') : '-';
                
                $telat = false;
                $pulangAwal = false;

                // Cek keterlambatan
                if ($attendance->check_in_time) {
                    $jamMasuk = Carbon::parse($attendance->check_in_time);
                    if ($jamMasuk->format('H:i:s') > '07:30:00') {
                        $telat = true;
                    }
                }

                // Cek pulang awal
                if ($attendance->check_out_time) {
                    $jamPulang = Carbon::parse($attendance->check_out_time);
                    if ($jamPulang->format('H:i:s') < '17:00:00') {
                        $pulangAwal = true;
                    }
                }

                // Hitung gaji berdasarkan status
                if (!$telat && !$pulangAwal && $attendance->check_in_time && $attendance->check_out_time) {
                    // Hadir tepat waktu
                    $gajiPokok = $gajiPokokHarian;
                    $tunjangan = $tunjanganHarian;
                    $status = 'hadir';
                } elseif ($telat || $pulangAwal) {
                    // Terlambat masuk ATAU pulang duluan ATAU keduanya
                    // Potongan tetap 20rb (tidak dikali 2)
                    $gajiPokok = $gajiPokokHarian;
                    $tunjangan = $tunjanganHarian;
                    $potongan = $potonganTelat; // Tetap 20rb
                    
                    // Tentukan status untuk display
                    if ($telat && $pulangAwal) {
                        $status = 'terlambat_masuk'; // Bisa juga 'terlambat_dan_pulang_awal'
                    } elseif ($telat) {
                        $status = 'terlambat_masuk';
                    } else {
                        $status = 'pulang_duluan';
                    }
                }
            } else {
                // Alpha (tidak ada attendance dan tidak ada leave)
                $gajiPokok = 0;
                $tunjangan = 0;
                $status = 'alpha';
            }

            // Akumulasi total
            $totalGajiPokok += $gajiPokok;
            $totalTunjangan += $tunjangan;
            $totalPotonganHarian += $potongan;

            // Simpan detail per hari
            $detailPerHari[] = [
                'date' => $dateStr,
                'status' => $status,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'basic_salary' => $gajiPokok,
                'allowance' => $tunjangan,
                'deduction' => $potongan,
            ];
        }

        // Hitung gaji bersih
        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        $gajiBersih = $gajiKotor - $totalPotonganHarian;

        // Pajak hanya dipotong jika bulan sudah selesai
        $totalSemuaPotongan = $totalPotonganHarian;
        $pajak = 0;
        if ($endDate->isEndOfMonth()) {
            $pajak = $pajakBulanan;
            $gajiBersih -= $pajakBulanan;
            $totalSemuaPotongan += $pajakBulanan;
        }

        return response()->json([
            'success' => true,
            'message' => 'Slip gaji berhasil dihitung',
            'is_final' => $endDate->isEndOfMonth(),
            'data' => [
                'user_id' => $user->id,
                'month' => $month,
                'year' => $year,
                'total_basic_salary' => $totalGajiPokok,
                'total_allowance' => $totalTunjangan,
                'total_deduction' => $totalPotonganHarian,
                'tax' => $pajak,
                'net_salary' => max(0, $gajiBersih),
                'daily_details' => $detailPerHari
            ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Error calculating payslip: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal menghitung slip gaji',
            'error' => $e->getMessage()
        ], 500);
    }
}
}