<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PayrollController extends Controller
{
    /**
     * Generate slip gaji untuk seorang user pada bulan dan tahun tertentu.
     */
    public function generate($userId, $year, $month)
    {
        $user = User::findOrFail($userId);
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        
        // --- ATURAN GAJI ---
        $gajiPokokHarian = 50000;
        $potonganTelat = 20000;
        $tunjanganHarian = 25000;
        $pajakBulanan = 100000;

        $totalGajiPokok = 0;
        $totalTunjangan = 0;
        $totalPotonganHarian = 0;
        $detailPerHari = [];

        // Ambil semua data absensi & cuti di bulan tersebut untuk efisiensi
        $attendances = Attendance::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()->keyBy(fn($item) => Carbon::parse($item->date)->toDateString());
            
        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->get();

        // Loop setiap hari dalam sebulan
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateString = $date->toDateString();
            $statusHariIni = 'Alpha';
            $gajiHariIni = 0;
            $tunjanganHariIni = 0;
            $potonganHariIni = 0;

            // 1. Cek Cuti/Izin yang disetujui
            $isOnLeave = false;
            foreach ($leaves as $leave) {
                if ($date->between(Carbon::parse($leave->start_date), Carbon::parse($leave->end_date))) {
                    $statusHariIni = $leave->type;
                    if ($leave->type === 'cuti') {
                        $gajiHariIni = $gajiPokokHarian;
                    }
                    $isOnLeave = true;
                    break;
                }
            }

            // 2. Jika tidak cuti/izin, cek data absensi
            if (!$isOnLeave && $attendances->has($dateString)) {
                $attendance = $attendances[$dateString];
                
                if($attendance->check_in_time && $attendance->check_out_time){
                    $statusHariIni = 'Hadir';
                    $tunjanganHariIni = $tunjanganHarian;

                    if ($attendance->status_check_in === 'Telat' || $attendance->status_check_out === 'Pulang Awal') {
                        $gajiHariIni = $gajiPokokHarian - $potonganTelat;
                        $potonganHariIni = $potonganTelat;
                    } else {
                        $gajiHariIni = $gajiPokokHarian;
                    }
                }
            }
            
            $totalGajiPokok += $gajiHariIni;
            $totalTunjangan += $tunjanganHariIni;
            $totalPotonganHarian += $potonganHariIni;
            
            $detailPerHari[] = [
                'tanggal' => $dateString,
                'status' => $statusHariIni,
                'gaji_harian' => $gajiHariIni,
                'tunjangan_harian' => $tunjanganHariIni,
                'potongan_harian' => $potonganHariIni,
                'pendapatan_hari_itu' => $gajiHariIni + $tunjanganHariIni,
            ];
        }
        
        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        $totalSemuaPotongan = $totalPotonganHarian + $pajakBulanan;
        $gajiBersih = $gajiKotor - $totalSemuaPotongan;

        if ($gajiBersih < 0) {
            $gajiBersih = 0;
        }

        $payroll = Payroll::updateOrCreate(
            ['user_id' => $userId, 'month' => $month, 'year' => $year],
            [
                'total_gaji_pokok' => $totalGajiPokok,
                'total_tunjangan' => $totalTunjangan,
                'total_potongan' => $totalSemuaPotongan,
                'pajak' => $pajakBulanan,
                'gaji_bersih' => $gajiBersih,
                'detail' => json_encode($detailPerHari)
            ]
        );

        return response()->json(['message' => 'Slip gaji berhasil dibuat.', 'data' => $payroll]);
    }

    /**
     * Menampilkan slip gaji untuk karyawan yang sedang login.
     */
    public function showForEmployee($year, $month)
    {
        $payroll = Payroll::where('user_id', Auth::id())
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if (!$payroll) {
            return response()->json(['message' => 'Slip gaji untuk periode ini tidak ditemukan.'], 404);
        }
        return response()->json($payroll);
    }

    /**
     * Menampilkan slip gaji untuk user tertentu (dilihat oleh admin).
     */
    public function showForAdmin($userId, $year, $month)
    {
        $payroll = Payroll::where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();
                
        if (!$payroll) {
            return response()->json(['message' => 'Slip gaji untuk periode ini tidak ditemukan.'], 404);
        }
        return response()->json($payroll);
    }

    /**
     * Memberikan ringkasan total gaji semua karyawan pada periode tertentu.
     */
    public function getPayrollSummary($year, $month)
    {
        $payrolls = Payroll::where('year', $year)->where('month', $month)->get();

        if ($payrolls->isEmpty()) {
            return response()->json(['message' => 'Tidak ada data gaji untuk periode ini.'], 404);
        }

        $summary = [
            'periode' => "{$year}-{$month}",
            'jumlah_karyawan_digaji' => $payrolls->count(),
            'total_gaji_pokok' => $payrolls->sum('total_gaji_pokok'),
            'total_tunjangan' => $payrolls->sum('total_tunjangan'),
            'total_potongan' => $payrolls->sum('total_potongan'),
            'total_gaji_bersih' => $payrolls->sum('gaji_bersih'),
        ];

        return response()->json($summary);
    }
}