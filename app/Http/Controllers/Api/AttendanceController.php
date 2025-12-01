<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\User;

class AttendanceController extends Controller
{
    public function checkIn(Request $request)
    {
        $request->validate([
            'latitude' => 'required|string',
            'longitude' => 'required|string',
        ]);

        $user = Auth::user();

        $existingLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->first();
        if ($existingLeave) {
            return response()->json(['message' => 'Anda tidak bisa absen karena sedang ' . $existingLeave->type], 400);
        }

        $todayAttendance = Attendance::where('user_id', $user->id)->whereDate('date', Carbon::today())->first();
        if ($todayAttendance) {
            return response()->json(['message' => 'Anda sudah melakukan absen masuk hari ini.'], 400);
        }

        $jamMasuk = Carbon::now();
        $jamMasukTime = $jamMasuk->format('H:i:s');

        if ($jamMasukTime > '09:00:00') {
            return response()->json(['message' => 'Waktu check-in sudah lewat batas (maksimal 09:00)'], 400);
        }

        $jamNormal = Carbon::parse(Carbon::today()->format('Y-m-d') . ' 08:00:00');
        $selisihMenit = $jamMasuk->diffInMinutes($jamNormal, false); 

        $statusCheckIn = 'Tepat Waktu';
        $potonganCheckIn = 0;

        if ($selisihMenit < 0) { 
            $menitTelat = abs($selisihMenit);

            if ($menitTelat >= 1 && $menitTelat <= 10) {
                $statusCheckIn = 'Tepat Waktu';
                $potonganCheckIn = 0;
            } elseif ($menitTelat >= 11 && $menitTelat <= 20) {
                $statusCheckIn = 'Telat';
                $potonganCheckIn = 5000;
            } elseif ($menitTelat >= 21 && $menitTelat <= 30) {
                $statusCheckIn = 'Telat';
                $potonganCheckIn = 10000;
            } elseif ($menitTelat >= 31 && $menitTelat <= 40) {
                $statusCheckIn = 'Telat';
                $potonganCheckIn = 15000;
            } elseif ($menitTelat >= 41 && $menitTelat <= 60) {
                $statusCheckIn = 'Telat';
                $potonganCheckIn = 20000;
            } else {
                $statusCheckIn = 'Alpha';
                $potonganCheckIn = 0;
            }
        }

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'check_in_time' => $jamMasuk,
            'check_in_location' => $request->latitude . ',' . $request->longitude,
            'date' => Carbon::today(),
            'status_check_in' => $statusCheckIn,
            'potongan_check_in' => $potonganCheckIn,
        ]);

        return response()->json([
            'message' => 'Absen masuk berhasil.',
            'data' => $attendance,
            'potongan' => $potonganCheckIn
        ], 201);
    }


    public function checkOut(Request $request)
    {
        $request->validate([
            'latitude' => 'required|string',
            'longitude' => 'required|string',
        ]);

        $user = Auth::user();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->whereNull('check_out_time')
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'Anda belum melakukan absen masuk atau sudah absen pulang.'], 404);
        }

        $jamPulang = Carbon::now();
        $jamPulangTime = $jamPulang->format('H:i:s');

        if ($jamPulangTime < '16:00:00') {
            return response()->json(['message' => 'Waktu check-out terlalu awal (minimal 16:00).'], 400);
        }

        $jamNormalPulang = Carbon::parse(Carbon::today()->format('Y-m-d') . ' 17:00:00');
        $selisihMenit = $jamPulang->diffInMinutes($jamNormalPulang, false); 

        $statusCheckOut = 'Tepat Waktu';
        $potonganCheckOut = 0;

        if ($jamPulangTime >= '17:25:00') {
            $statusCheckOut = 'Overtime';
            $potonganCheckOut = 0;
        } elseif ($selisihMenit > 0) { 
            $menitAwal = $selisihMenit;

            if ($menitAwal >= 1 && $menitAwal <= 10) {
                $statusCheckOut = 'Tepat Waktu';
                $potonganCheckOut = 0;
            } elseif ($menitAwal >= 11 && $menitAwal <= 20) {
                $statusCheckOut = 'Pulang Lebih Awal';
                $potonganCheckOut = 5000;
            } elseif ($menitAwal >= 21 && $menitAwal <= 30) {
                $statusCheckOut = 'Pulang Lebih Awal';
                $potonganCheckOut = 10000;
            } elseif ($menitAwal >= 31 && $menitAwal <= 40) {
                $statusCheckOut = 'Pulang Lebih Awal';
                $potonganCheckOut = 15000;
            } elseif ($menitAwal >= 41) {
                $statusCheckOut = 'Pulang Lebih Awal';
                $potonganCheckOut = 20000;
            }
        }

        $attendance->update([
            'check_out_time' => $jamPulang,
            'check_out_location' => $request->latitude . ',' . $request->longitude,
            'status_check_out' => $statusCheckOut,
            'potongan_check_out' => $potonganCheckOut,
        ]);

        return response()->json([
            'message' => 'Absen pulang berhasil.',
            'data' => $attendance,
            'potongan' => $potonganCheckOut
        ]);
    }

    public function history()
    {
        $user = Auth::user();
        $attendances = Attendance::where('user_id', $user->id)->orderBy('date', 'desc')->get();
        $leaveRequests = LeaveRequest::where('user_id', $user->id)->orderBy('start_date', 'desc')->get();

        $attendances->transform(function ($att) {
            $att->status_manual = $this->determineManualStatusForRecord($att);
            return $att;
        });

        $combinedData = $attendances->concat($leaveRequests);
        $sortedData = $combinedData->sortByDesc(function ($item) {
            return $item->created_at ?? ($item->date ?? now());
        });

        return response()->json($sortedData->values()->all());
    }

    
    public function historyForAdmin($userId)
    {
        $history = Attendance::where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($att) {
                $att->status_manual = $this->determineManualStatusForRecord($att);
                return $att;
            });
        return response()->json($history);
    }

    
    public function getTodayStatus()
    {
        $user = Auth::user();

        $leave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->first();
        if ($leave) {
            return response()->json(['status' => $leave->type]);
        }

        $attendance = Attendance::where('user_id', $user->id)->whereDate('date', Carbon::today())->first();
        if ($attendance) {
            if ($attendance->check_in_time && !$attendance->check_out_time) {
                return response()->json(['status' => 'pending']);
            }
            return response()->json(['status' => ($attendance->check_out_time ? 'checked_out' : 'checked_in')]);
        }

        return response()->json(['status' => 'none']);
    }

    
    public function historyForEmployee($userId)
    {
        try {
            $attendances = Attendance::where('user_id', $userId)
                ->orderBy('date', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'date' => $item->date,
                        'check_in_time' => $item->check_in_time ? Carbon::parse($item->check_in_time)->format('H:i:s') : null,
                        'check_out_time' => $item->check_out_time ? Carbon::parse($item->check_out_time)->format('H:i:s') : null,
                        'check_in_location' => $item->check_in_location,
                        'check_out_location' => $item->check_out_location,
                        'status_check_in' => $item->status_check_in,
                        'status_check_out' => $item->status_check_out,
                        'potongan_check_in' => $item->potongan_check_in ?? 0,
                        'potongan_check_out' => $item->potongan_check_out ?? 0,
                        'manual_status' => $this->determineManualStatusForRecord($item),
                    ];
                });

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


    public function updatePendingStatus()
    {
        try {
            $records = Attendance::whereNull('check_out_time')
                ->whereDate('date', '<', Carbon::today())
                ->get();

            foreach ($records as $rec) {
                $rec->update([
                    'status_check_out' => 'Tidak Hadir',
                    'potongan_check_out' => 0,
                ]);
            }

            return response()->json(['message' => 'Pending attendance updated.', 'count' => $records->count()]);
        } catch (\Exception $e) {
            \Log::error('Error updating pending status: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal memperbarui pending status', 'error' => $e->getMessage()], 500);
        }
    }

    private function determineManualStatusForRecord($att)
    {
        if ($att->check_in_time && !$att->check_out_time) {
            $attDate = Carbon::parse($att->date);
            if ($attDate->isToday()) {
                return 'pending';
            } elseif ($attDate->lt(Carbon::today())) {
                return 'alpha';
            }
        }

        if ($att->check_in_time && $att->check_out_time) {
            if (isset($att->status_check_in) && $att->status_check_in === 'Alpha') {
                return 'alpha';
            }
            return 'hadir';
        }

        return 'alpha';
    }
public function calculateLivePayslip($year, $month)
{
    try {
        $user = Auth::user();
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        if ($startDate->isBefore(Carbon::now()->startOfMonth())) {
            $endDate = $startDate->copy()->endOfMonth();
        }

        $gajiPokokHarian = 50000;
        $tunjanganHarian = 25000;
        $pajakBulanan = 100000;

        $totalGajiPokok = 0;
        $totalTunjangan = 0;
        $totalPotonganHarian = 0;
        $detailPerHari = [];

        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn($item) => Carbon::parse($item->date)->toDateString());

        $leaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateStr = $date->toDateString();
            $attendance = $attendances->get($dateStr);

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
                $checkIn = $attendance->check_in_time ? Carbon::parse($attendance->check_in_time)->format('H:i:s') : '-';
                $checkOut = $attendance->check_out_time ? Carbon::parse($attendance->check_out_time)->format('H:i:s') : '-';

                if (!$attendance->check_out_time) {
                    if ($date->isToday()) {
                        $status = 'pending';
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                    } else {
                        $status = 'alpha';
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                    }
                } else {
                    $potonganCheckIn = $attendance->potongan_check_in ?? 0;
                    $potonganCheckOut = $attendance->potongan_check_out ?? 0;

                    if ($attendance->status_check_in === 'Alpha') {
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                        $status = 'alpha';
                    } else {
                        $gajiPokok = $gajiPokokHarian;
                        $tunjangan = $tunjanganHarian;
                        $potongan = $potonganCheckIn + $potonganCheckOut;

                        if ($attendance->status_check_in === 'Telat' || $attendance->status_check_out === 'Pulang Lebih Awal') {
                            $status = 'hadir_dengan_potongan';
                        } else {
                            $status = 'hadir';
                        }
                    }
                }
            }

            $totalGajiPokok += $gajiPokok;
            $totalTunjangan += $tunjangan;
            $totalPotonganHarian += $potongan;

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

        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        
        // 🔧 FIX: Hanya potong pajak jika ada pendapatan
        $pajak = 0;
        if ($endDate->isEndOfMonth() && $gajiKotor > 0) {
            $pajak = $pajakBulanan;
        }
        
        $totalSemuaPotongan = $totalPotonganHarian + $pajak;
        
        // 🔧 FIX: Gaji bersih tidak boleh negatif
        $gajiBersih = max(0, $gajiKotor - $totalSemuaPotongan);

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
                'net_salary' => $gajiBersih,
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

public function calculateLivePayslipForAdmin($userId, $year, $month)
{
    try {
        $user = User::findOrFail($userId);
        
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        if ($startDate->isBefore(Carbon::now()->startOfMonth())) {
            $endDate = $startDate->copy()->endOfMonth();
        }

        $gajiPokokHarian = 50000;
        $tunjanganHarian = 25000;
        $pajakBulanan = 100000;

        $totalGajiPokok = 0;
        $totalTunjangan = 0;
        $totalPotonganHarian = 0;
        $detailPerHari = [];

        $attendances = Attendance::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn($item) => Carbon::parse($item->date)->toDateString());

        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateStr = $date->toDateString();
            $attendance = $attendances->get($dateStr);

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
                $checkIn = $attendance->check_in_time ? Carbon::parse($attendance->check_in_time)->format('H:i:s') : '-';
                $checkOut = $attendance->check_out_time ? Carbon::parse($attendance->check_out_time)->format('H:i:s') : '-';

                if (!$attendance->check_out_time) {
                    if ($date->isToday()) {
                        $status = 'pending';
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                    } else {
                        $status = 'alpha';
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                    }
                } else {
                    $potonganCheckIn = $attendance->potongan_check_in ?? 0;
                    $potonganCheckOut = $attendance->potongan_check_out ?? 0;

                    if ($attendance->status_check_in === 'Alpha') {
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                        $status = 'alpha';
                    } else {
                        $gajiPokok = $gajiPokokHarian;
                        $tunjangan = $tunjanganHarian;
                        $potongan = $potonganCheckIn + $potonganCheckOut;

                        if ($attendance->status_check_in === 'Telat' || $attendance->status_check_out === 'Pulang Lebih Awal') {
                            $status = 'hadir_dengan_potongan';
                        } else {
                            $status = 'hadir';
                        }
                    }
                }
            }

            $totalGajiPokok += $gajiPokok;
            $totalTunjangan += $tunjangan;
            $totalPotonganHarian += $potongan;

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

        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        
        // 🔧 FIX: Hanya potong pajak jika ada pendapatan
        $pajak = 0;
        if ($endDate->isEndOfMonth() && $gajiKotor > 0) {
            $pajak = $pajakBulanan;
        }
        
        $totalSemuaPotongan = $totalPotonganHarian + $pajak;
        
        // 🔧 FIX: Gaji bersih tidak boleh negatif
        $gajiBersih = max(0, $gajiKotor - $totalSemuaPotongan);

        return response()->json([
            'success' => true,
            'message' => 'Slip gaji berhasil dihitung',
            'is_final' => $endDate->isEndOfMonth(),
            'data' => [
                'user_id' => $userId,
                'month' => $month,
                'year' => $year,
                'total_basic_salary' => $totalGajiPokok,
                'total_allowance' => $totalTunjangan,
                'total_deduction' => $totalPotonganHarian,
                'tax' => $pajak,
                'net_salary' => $gajiBersih,
                'daily_details' => $detailPerHari
            ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Error calculating payslip for admin: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal menghitung slip gaji',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
