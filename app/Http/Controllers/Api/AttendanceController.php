<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Payroll; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\User;

class AttendanceController extends Controller
{
      // 🔧 Konstanta gaji
    private const GAJI_POKOK_HARIAN = 50000;
    private const TUNJANGAN_HARIAN = 25000;
    private const PAJAK_BULANAN = 100000;

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
        } elseif ($menitTelat >= 41 && $menitTelat <= 50) {  
            $statusCheckIn = 'Telat';
            $potonganCheckIn = 20000;
        } elseif ($menitTelat >= 51 && $menitTelat <= 60) {  
            $statusCheckIn = 'Telat';
            $potonganCheckIn = 25000;  
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
            } elseif ($menitAwal >= 41 && $menitAwal <= 50) {  
                $statusCheckOut = 'Pulang Lebih Awal';
                $potonganCheckOut = 20000;
            } elseif ($menitAwal >= 51) {  
                $statusCheckOut = 'Pulang Lebih Awal';
                $potonganCheckOut = 25000; 
            }
        }

        $attendance->update([
            'check_out_time' => $jamPulang,
            'check_out_location' => $request->latitude . ',' . $request->longitude,
            'status_check_out' => $statusCheckOut,
            'potongan_check_out' => $potonganCheckOut,
        ]);

        // 🔥 OTOMATIS UPDATE PAYROLL SETELAH CHECKOUT
        $this->updateMonthlyPayroll($user->id, Carbon::today());

        return response()->json([
            'message' => 'Absen pulang berhasil dan payroll telah diupdate.',
            'data' => $attendance,
            'potongan' => $potonganCheckOut
        ]);
    }

   private function updateMonthlyPayroll($userId, $date)
{
    $month = $date->month;
    $year = $date->year;

    $startDate = Carbon::create($year, $month, 1)->startOfDay();
    $endDate = Carbon::now()->endOfDay();

    if ($startDate->isBefore(Carbon::now()->startOfMonth())) {
        $endDate = $startDate->copy()->endOfMonth();
    }

    $totalGajiPokok = 0;
    $totalTunjangan = 0;
    $totalPotonganHarian = 0;
    $detailPerHari = [];

    $attendances = Attendance::where('user_id', $userId)
        ->whereBetween('date', [$startDate, $endDate])
        ->get()
        ->keyBy(fn($item) => Carbon::parse($item->date)->toDateString());

    // ✅ GANTI QUERY INI
    $leaves = LeaveRequest::where('user_id', $userId)
        ->where('status', 'approved')
        ->where(function($query) use ($startDate, $endDate) {
            $query->where(function($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate]);
            })
            ->orWhere(function($q) use ($startDate, $endDate) {
                $q->whereBetween('end_date', [$startDate, $endDate]);
            })
            ->orWhere(function($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $startDate)
                  ->where('end_date', '>=', $endDate);
            });
        })
        ->get();

    for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
        $dateStr = $d->toDateString();
        $attendance = $attendances->get($dateStr);

        $leave = $leaves->first(function($l) use ($d) {
            return $d->between(Carbon::parse($l->start_date), Carbon::parse($l->end_date));
        });

        $gajiPokok = 0;
        $tunjangan = 0;
        $potongan = 0;
        $status = 'alpha';

        if ($leave) {
            if ($leave->type === 'cuti') {
                $gajiPokok = self::GAJI_POKOK_HARIAN;
                $status = 'cuti';
            } else {
                $status = 'izin';
            }
        } elseif ($attendance && $attendance->check_out_time) {
            $potonganCheckIn = $attendance->potongan_check_in ?? 0;
            $potonganCheckOut = $attendance->potongan_check_out ?? 0;

            if ($attendance->status_check_in === 'Alpha' || $attendance->status_check_out === 'Tidak Hadir') {
                $gajiPokok = 0;
                $tunjangan = 0;
                $status = 'alpha';
            } else {
                $gajiPokok = self::GAJI_POKOK_HARIAN;
                $tunjangan = self::TUNJANGAN_HARIAN;
                $potongan = $potonganCheckIn + $potonganCheckOut;
                $status = 'hadir';
            }
        }

        $totalGajiPokok += $gajiPokok;
        $totalTunjangan += $tunjangan;
        $totalPotonganHarian += $potongan;

        $detailPerHari[] = [
            'tanggal' => $dateStr,
            'status' => $status,
            'gaji_harian' => $gajiPokok,
            'tunjangan_harian' => $tunjangan,
            'potongan_harian' => $potongan,
        ];
    }

    $gajiKotor = $totalGajiPokok + $totalTunjangan;
    
    $pajak = 0;
    if ($endDate->isEndOfMonth() && $gajiKotor > 0) {
        $pajak = self::PAJAK_BULANAN;
    }
    
    $totalSemuaPotongan = $totalPotonganHarian + $pajak;
    $gajiBersih = max(0, $gajiKotor - $totalSemuaPotongan);

    Payroll::updateOrCreate(
        ['user_id' => $userId, 'month' => $month, 'year' => $year],
        [
            'total_gaji_pokok' => $totalGajiPokok,
            'total_tunjangan' => $totalTunjangan,
            'total_potongan' => $totalSemuaPotongan,
            'pajak' => $pajak,
            'gaji_bersih' => $gajiBersih,
            'detail' => json_encode($detailPerHari)
        ]
    );
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

        // ✅ GANTI QUERY INI
        $leaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where(function($query) use ($startDate, $endDate) {
                $query->where(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('end_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $startDate)
                      ->where('end_date', '>=', $endDate);
                });
            })
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
        
        $pajak = 0;
        if ($endDate->isEndOfMonth() && $gajiKotor > 0) {
            $pajak = $pajakBulanan;
        }
        
        $totalSemuaPotongan = $totalPotonganHarian + $pajak;
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

        // ✅ GANTI QUERY INI
        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where(function($query) use ($startDate, $endDate) {
                $query->where(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('end_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $startDate)
                      ->where('end_date', '>=', $endDate);
                });
            })
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
        
        $pajak = 0;
        if ($endDate->isEndOfMonth() && $gajiKotor > 0) {
            $pajak = $pajakBulanan;
        }
        
        $totalSemuaPotongan = $totalPotonganHarian + $pajak;
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
public function getMonthlyStats()
{
    try {
        $user = Auth::user();
        $now = Carbon::now();
        
        // 🔥 UNTUK BULAN INI (izin dan alpha)
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfDay();

        // 🔥 UNTUK TAHUN INI (cuti)
        $startOfYear = $now->copy()->startOfYear();
        $endOfYear = $now->copy()->endOfYear();

        \Log::info("📅 Calculating stats for user: {$user->id}");
        \Log::info("📅 Month range: {$startOfMonth} to {$endOfMonth}");
        \Log::info("📅 Year range: {$startOfYear} to {$endOfYear}");

        // Ambil attendance bulan ini
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get();

        \Log::info("📊 Total attendances this month: " . $attendances->count());

        // 🔥 AMBIL SEMUA CUTI TAHUN INI (approved)
        $cutiThisYear = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('type', 'cuti')
            ->where(function($query) use ($startOfYear, $endOfYear) {
                $query->where(function($q) use ($startOfYear, $endOfYear) {
                    // Cuti yang mulai di tahun ini
                    $q->whereBetween('start_date', [$startOfYear, $endOfYear]);
                })
                ->orWhere(function($q) use ($startOfYear, $endOfYear) {
                    // Cuti yang berakhir di tahun ini
                    $q->whereBetween('end_date', [$startOfYear, $endOfYear]);
                })
                ->orWhere(function($q) use ($startOfYear, $endOfYear) {
                    // Cuti yang melewati seluruh tahun ini
                    $q->where('start_date', '<=', $startOfYear)
                      ->where('end_date', '>=', $endOfYear);
                });
            })
            ->get();

        \Log::info("🏖️ Total cuti records this year: " . $cutiThisYear->count());

        // 🔥 AMBIL SEMUA IZIN BULAN INI (approved)
        $izinThisMonth = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('type', 'izin')
            ->where(function($query) use ($startOfMonth, $endOfMonth) {
                $query->where(function($q) use ($startOfMonth, $endOfMonth) {
                    $q->whereBetween('start_date', [$startOfMonth, $endOfMonth]);
                })
                ->orWhere(function($q) use ($startOfMonth, $endOfMonth) {
                    $q->whereBetween('end_date', [$startOfMonth, $endOfMonth]);
                })
                ->orWhere(function($q) use ($startOfMonth, $endOfMonth) {
                    $q->where('start_date', '<=', $startOfMonth)
                      ->where('end_date', '>=', $endOfMonth);
                });
            })
            ->get();

        \Log::info("🤒 Total izin records this month: " . $izinThisMonth->count());

        // 🔥 HITUNG HARI CUTI TAHUN INI (SEMUA HARI TERMASUK WEEKEND)
        $totalHariCuti = 0;
        foreach ($cutiThisYear as $cuti) {
            $cutiStart = Carbon::parse($cuti->start_date);
            $cutiEnd = Carbon::parse($cuti->end_date);
            
            // Batasi ke range tahun ini
            if ($cutiStart->lt($startOfYear)) {
                $cutiStart = $startOfYear->copy();
            }
            if ($cutiEnd->gt($endOfYear)) {
                $cutiEnd = $endOfYear->copy();
            }
            
            // 🔥 HITUNG SEMUA HARI (TERMASUK SABTU-MINGGU)
            $hariCutiRecord = $cutiStart->diffInDays($cutiEnd) + 1;
            
            $totalHariCuti += $hariCutiRecord;
            
            \Log::info("🏖️ Cuti ID {$cuti->id}: {$cuti->start_date} → {$cuti->end_date}");
            \Log::info("   📅 Total hari dihitung: {$hariCutiRecord} hari (termasuk weekend)");
        }

        \Log::info("🏖️ TOTAL HARI CUTI TAHUN INI: {$totalHariCuti}");

        // 🔥 HITUNG HARI IZIN BULAN INI (SEMUA HARI TERMASUK WEEKEND)
        $totalHariIzin = 0;
        foreach ($izinThisMonth as $izin) {
            $izinStart = Carbon::parse($izin->start_date);
            $izinEnd = Carbon::parse($izin->end_date);
            
            // Batasi ke range bulan ini
            if ($izinStart->lt($startOfMonth)) {
                $izinStart = $startOfMonth->copy();
            }
            if ($izinEnd->gt($endOfMonth)) {
                $izinEnd = $endOfMonth->copy();
            }
            
            // 🔥 HITUNG SEMUA HARI (TERMASUK SABTU-MINGGU)
            $hariIzinRecord = $izinStart->diffInDays($izinEnd) + 1;
            
            $totalHariIzin += $hariIzinRecord;
            
            \Log::info("🤒 Izin ID {$izin->id}: {$izin->start_date} → {$izin->end_date}");
            \Log::info("   📅 Total hari dihitung: {$hariIzinRecord} hari (termasuk weekend)");
        }

        \Log::info("🤒 TOTAL HARI IZIN BULAN INI: {$totalHariIzin}");

        // 🔥 HITUNG ALPHA BULAN INI (TERMASUK WEEKEND)
        $totalAlpha = 0;
        $currentDate = $startOfMonth->copy();
        
        \Log::info("🔍 Mulai hitung alpha dari {$startOfMonth->format('Y-m-d')} sampai {$endOfMonth->format('Y-m-d')}");
        
        while ($currentDate->lte($endOfMonth)) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayName = $currentDate->locale('id')->dayName;
            
            // ⏭️ Skip hari ini dan masa depan
            if ($currentDate->gte(Carbon::today())) {
                \Log::info("   ⏭️  SKIP: {$dateStr} ({$dayName}) - Masa depan/hari ini");
                $currentDate->addDay();
                continue;
            }

            // 🔍 Cek apakah ada attendance yang sudah checkout
            $hasAttendance = $attendances->contains(function($att) use ($currentDate) {
                $isSameDay = Carbon::parse($att->date)->isSameDay($currentDate);
                $hasCheckout = $att->check_out_time !== null;
                return $isSameDay && $hasCheckout;
            });

            // 🔍 Cek apakah ada cuti (dari data cuti tahun ini)
            $hasCuti = $cutiThisYear->contains(function($cuti) use ($currentDate) {
                return $currentDate->between(
                    Carbon::parse($cuti->start_date),
                    Carbon::parse($cuti->end_date)
                );
            });

            // 🔍 Cek apakah ada izin (dari data izin bulan ini)
            $hasIzin = $izinThisMonth->contains(function($izin) use ($currentDate) {
                return $currentDate->between(
                    Carbon::parse($izin->start_date),
                    Carbon::parse($izin->end_date)
                );
            });

            // 🔴 Jika tidak ada attendance, cuti, dan izin = alpha
            if (!$hasAttendance && !$hasCuti && !$hasIzin) {
                $totalAlpha++;
                \Log::info("   ❌ ALPHA: {$dateStr} ({$dayName}) - Tidak ada attendance/cuti/izin");
            } else {
                $status = [];
                if ($hasAttendance) $status[] = 'hadir';
                if ($hasCuti) $status[] = 'cuti';
                if ($hasIzin) $status[] = 'izin';
                \Log::info("   ✅ OK: {$dateStr} ({$dayName}) - " . implode(', ', $status));
            }

            $currentDate->addDay();
        }

        \Log::info("❌ TOTAL ALPHA BULAN INI: {$totalAlpha}");

        // 🔥 HITUNG SISA CUTI (12 - yang sudah terpakai)
        $sisaCuti = max(0, 12 - $totalHariCuti);
        
        \Log::info("🧮 Perhitungan Sisa Cuti:");
        \Log::info("   Jatah awal: 12 hari");
        \Log::info("   Sudah terpakai: {$totalHariCuti} hari");
        \Log::info("   Sisa: {$sisaCuti} hari");

        $result = [
            'cuti_tahun_ini' => $totalHariCuti,
            'izin_bulan_ini' => $totalHariIzin,
            'alpha_bulan_ini' => $totalAlpha,
            'sisa_cuti' => $sisaCuti,
        ];

        \Log::info("✅ Final Result: " . json_encode($result));

        return response()->json([
            'success' => true,
            'data' => $result
        ], 200);

    } catch (\Exception $e) {
        \Log::error('❌ Error getting monthly stats: ' . $e->getMessage());
        \Log::error('Stack trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil statistik',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function getYearlyStats($year)
{
    try {
        $user = Auth::user();
        
        $startDate = Carbon::create($year, 1, 1)->startOfDay();
        $endDate = Carbon::create($year, 12, 31)->endOfDay();
        
        // Jika tahun yang diminta adalah tahun sekarang, batasi sampai hari ini
        if ($year == Carbon::now()->year) {
            $endDate = Carbon::now()->endOfDay();
        }

        // Ambil semua attendance dalam 1 tahun
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        // Ambil semua leave requests yang approved dalam 1 tahun
        $leaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where(function($query) use ($startDate, $endDate) {
                $query->where(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('end_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $startDate)
                      ->where('end_date', '>=', $endDate);
                });
            })
            ->get();

        // Hitung statistik
        $totalCuti = 0;
        $totalIzin = 0;
        $totalAlpha = 0;

        // Hitung dari attendance
        foreach ($attendances as $att) {
            $statusCheckIn = strtolower($att->status_check_in ?? '');
            
            if ($statusCheckIn === 'cuti') {
                $totalCuti++;
            } elseif ($statusCheckIn === 'izin') {
                $totalIzin++;
            } elseif (!$att->check_out_time && Carbon::parse($att->date)->lt(Carbon::today())) {
                // Jika tidak ada checkout dan tanggalnya sudah lewat = alpha
                $totalAlpha++;
            } elseif ($statusCheckIn === 'alpha') {
                $totalAlpha++;
            }
        }

        // Hitung hari-hari yang tidak ada attendance sama sekali (alpha)
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            // Skip weekend (Sabtu & Minggu)
            if ($currentDate->isWeekend()) {
                $currentDate->addDay();
                continue;
            }

            // Skip hari ini dan masa depan
            if ($currentDate->gte(Carbon::today())) {
                $currentDate->addDay();
                continue;
            }

            // Cek apakah ada attendance di tanggal ini
            $hasAttendance = $attendances->contains(function($att) use ($currentDate) {
                return Carbon::parse($att->date)->isSameDay($currentDate);
            });

            // Cek apakah ada leave di tanggal ini
            $hasLeave = $leaves->contains(function($leave) use ($currentDate) {
                return $currentDate->between(
                    Carbon::parse($leave->start_date),
                    Carbon::parse($leave->end_date)
                );
            });

            // Jika tidak ada attendance dan tidak ada leave = alpha
            if (!$hasAttendance && !$hasLeave) {
                $totalAlpha++;
            }

            $currentDate->addDay();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'total_cuti' => $totalCuti,
                'total_izin' => $totalIzin,
                'total_alpha' => $totalAlpha,
            ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Error getting yearly stats: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil statistik tahunan',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
