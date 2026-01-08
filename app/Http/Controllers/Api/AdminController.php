<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdminController extends Controller
{
    // Konstanta gaji
    private const GAJI_POKOK_HARIAN = 50000;
    private const TUNJANGAN_HARIAN = 25000;
    private const PAJAK_BULANAN = 100000;

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

    public function updateEmployeeStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:aktif,tidak_aktif',
        ]);

        $employee = User::find($id);

        if (!$employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        if ($employee->role !== 'karyawan') {
            return response()->json(['message' => 'Tidak bisa mengubah status admin.'], 403);
        }

        $employee->status = $request->status;
        $employee->save();

        $statusText = $request->status === 'aktif' ? 'diaktifkan' : 'dinonaktifkan';
        
        return response()->json([
            'message' => "Status karyawan berhasil {$statusText}.",
            'employee' => $employee
        ]);
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

    /**
     * ⚡ ENDPOINT BARU: Total Gaji Bersih per Bulan (untuk Chart)
     * Hanya 1 request untuk semua data chart!
     */
    public function getYearlyTotalSalary($year)
    {
        try {
            $startDate = Carbon::create($year, 1, 1)->startOfDay();
            $endDate = Carbon::create($year, 12, 31)->endOfDay();

            // Ambil SEMUA data yang diperlukan dalam 1 query
            $employees = User::where('role', 'karyawan')->pluck('id');
            
            $attendances = Attendance::whereIn('user_id', $employees)
                ->whereBetween('date', [$startDate, $endDate])
                ->get()
                ->groupBy(function($item) {
                    return Carbon::parse($item->date)->format('Y-m');
                });

            $leaves = LeaveRequest::whereIn('user_id', $employees)
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

            $yearlyData = [];

            // Loop 12 bulan
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                
                // Jika bulan di masa depan, skip
                if ($monthStart->gt(Carbon::now())) {
                    $yearlyData[] = [
                        'month' => $month,
                        'monthName' => $monthStart->locale('id')->format('MMM'),
                        'total' => 0,
                    ];
                    continue;
                }

                // Batasi sampai hari ini jika bulan ini
                if ($monthEnd->gt(Carbon::now())) {
                    $monthEnd = Carbon::now()->endOfDay();
                }

                $monthKey = $monthStart->format('Y-m');
                $monthAttendances = $attendances->get($monthKey, collect());

                $totalGaji = 0;

                // Hitung per karyawan
                foreach ($employees as $userId) {
                    $userAttendances = $monthAttendances->where('user_id', $userId);
                    
                    $userLeaves = $leaves->filter(function($leave) use ($userId, $monthStart, $monthEnd) {
                        return $leave->user_id == $userId && 
                               Carbon::parse($leave->start_date)->lte($monthEnd) &&
                               Carbon::parse($leave->end_date)->gte($monthStart);
                    });

                    $gajiPokok = 0;
                    $tunjangan = 0;
                    $potongan = 0;

                    // Loop setiap hari di bulan ini
                    for ($date = $monthStart->copy(); $date->lte($monthEnd); $date->addDay()) {
                        $dateStr = $date->toDateString();
                        
                        $attendance = $userAttendances->firstWhere('date', $dateStr);
                        $leave = $userLeaves->first(function($l) use ($date) {
                            return $date->between(Carbon::parse($l->start_date), Carbon::parse($l->end_date));
                        });

                        if ($leave) {
                            if ($leave->type === 'cuti') {
                                $gajiPokok += self::GAJI_POKOK_HARIAN;
                            }
                        } elseif ($attendance && $attendance->check_out_time) {
                            if ($attendance->status_check_in !== 'Alpha' && $attendance->status_check_out !== 'Tidak Hadir') {
                                $gajiPokok += self::GAJI_POKOK_HARIAN;
                                $tunjangan += self::TUNJANGAN_HARIAN;
                                $potongan += ($attendance->potongan_check_in ?? 0) + ($attendance->potongan_check_out ?? 0);
                            }
                        }
                    }

                    $gajiKotor = $gajiPokok + $tunjangan;
                    $pajak = 0;
                    if ($monthEnd->isEndOfMonth() && $gajiKotor > 0) {
                        $pajak = self::PAJAK_BULANAN;
                    }
                    
                    $gajiBersih = max(0, $gajiKotor - $potongan - $pajak);
                    $totalGaji += $gajiBersih;
                }

                $yearlyData[] = [
                    'month' => $month,
                    'monthName' => $monthStart->locale('id')->format('MMM'),
                    'total' => $totalGaji,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $yearlyData
            ]);

        } catch (\Exception $e) {
            \Log::error('Error getting yearly salary data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data gaji tahunan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⚡ ENDPOINT BARU: Top 3 Gaji Tertinggi Tahunan
     * Hanya 1 request untuk semua data!
     */
    public function getTopThreeYearly($year)
    {
        try {
            $startDate = Carbon::create($year, 1, 1)->startOfDay();
            $endDate = Carbon::create($year, 12, 31)->endOfDay();

            // Ambil SEMUA data yang diperlukan dalam 1 query
            $employees = User::where('role', 'karyawan')->get();
            
            $attendances = Attendance::whereIn('user_id', $employees->pluck('id'))
                ->whereBetween('date', [$startDate, $endDate])
                ->get()
                ->groupBy('user_id');

            $leaves = LeaveRequest::whereIn('user_id', $employees->pluck('id'))
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
                ->get()
                ->groupBy('user_id');

            $employeeSalaries = [];

            // Hitung per karyawan
            foreach ($employees as $employee) {
                $userId = $employee->id;
                $userAttendances = $attendances->get($userId, collect());
                $userLeaves = $leaves->get($userId, collect());

                $totalYearlySalary = 0;

                // Loop 12 bulan
                for ($month = 1; $month <= 12; $month++) {
                    $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                    $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                    
                    if ($monthStart->gt(Carbon::now())) {
                        continue;
                    }

                    if ($monthEnd->gt(Carbon::now())) {
                        $monthEnd = Carbon::now()->endOfDay();
                    }

                    $gajiPokok = 0;
                    $tunjangan = 0;
                    $potongan = 0;

                    for ($date = $monthStart->copy(); $date->lte($monthEnd); $date->addDay()) {
                        $dateStr = $date->toDateString();
                        
                        $attendance = $userAttendances->firstWhere('date', $dateStr);
                        $leave = $userLeaves->first(function($l) use ($date) {
                            return $date->between(Carbon::parse($l->start_date), Carbon::parse($l->end_date));
                        });

                        if ($leave) {
                            if ($leave->type === 'cuti') {
                                $gajiPokok += self::GAJI_POKOK_HARIAN;
                            }
                        } elseif ($attendance && $attendance->check_out_time) {
                            if ($attendance->status_check_in !== 'Alpha' && $attendance->status_check_out !== 'Tidak Hadir') {
                                $gajiPokok += self::GAJI_POKOK_HARIAN;
                                $tunjangan += self::TUNJANGAN_HARIAN;
                                $potongan += ($attendance->potongan_check_in ?? 0) + ($attendance->potongan_check_out ?? 0);
                            }
                        }
                    }

                    $gajiKotor = $gajiPokok + $tunjangan;
                    $pajak = 0;
                    if ($monthEnd->isEndOfMonth() && $gajiKotor > 0) {
                        $pajak = self::PAJAK_BULANAN;
                    }
                    
                    $gajiBersih = max(0, $gajiKotor - $potongan - $pajak);
                    $totalYearlySalary += $gajiBersih;
                }

                if ($totalYearlySalary > 0) {
                    $employeeSalaries[] = [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'nik' => $employee->nik,
                        'jabatan' => $employee->jabatan,
                        'totalSalary' => $totalYearlySalary,
                    ];
                }
            }

            // Sort dan ambil top 3
            usort($employeeSalaries, function($a, $b) {
                return $b['totalSalary'] - $a['totalSalary'];
            });

            $topThree = array_slice($employeeSalaries, 0, 3);

            return response()->json([
                'success' => true,
                'data' => $topThree
            ]);

        } catch (\Exception $e) {
            \Log::error('Error getting top three yearly: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat top 3 gaji tahunan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}