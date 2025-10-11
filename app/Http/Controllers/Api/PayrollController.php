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
    public function generate($userId, $year, $month)
    {
        $user = User::findOrFail($userId);
        $startDate = Carbon::createFromDate($year, $month, 1);
        $endDate = $startDate->copy()->endOfMonth();
        $daysInMonth = $startDate->daysInMonth;

        $gajiPokokHarian = 50000;
        $potonganTelat = 25000;
        $tunjanganHarian = 25000;
        $pajakBulanan = 100000;

        $totalGajiPokok = 0;
        $totalTunjangan = 0;
        $totalPotongan = 0;

        // Ambil semua data absensi dan cuti di bulan tersebut
        $attendances = Attendance::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()->keyBy('date');
            
        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where(function($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                      ->orWhereBetween('end_date', [$startDate, $endDate]);
            })->get();

        // Loop setiap hari dalam sebulan
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = Carbon::createFromDate($year, $month, $day);
            $dateString = $currentDate->toDateString();

            $isOnLeave = false;
            foreach ($leaves as $leave) {
                if ($currentDate->between(Carbon::parse($leave->start_date), Carbon::parse($leave->end_date))) {
                    if ($leave->type === 'cuti') {
                        $totalGajiPokok += $gajiPokokHarian;
                    }
                    // Jika 'izin' atau 'alpha', tidak dapat apa-apa
                    $isOnLeave = true;
                    break;
                }
            }

            if (!$isOnLeave && isset($attendances[$dateString])) {
                $attendance = $attendances[$dateString];
                
                // Asumsi jam kerja 09:00 - 17:00
                $jamMasuk = Carbon::parse($attendance->check_in_time);
                $jamPulang = Carbon::parse($attendance->check_out_time);
                $isTelat = $jamMasuk->hour > 9;
                $isPulangCepat = $jamPulang->hour < 17;

                if ($isTelat || $isPulangCepat) {
                    $totalGajiPokok += ($gajiPokokHarian - $potonganTelat);
                    $totalPotongan += $potonganTelat;
                } else {
                    $totalGajiPokok += $gajiPokokHarian;
                }
                $totalTunjangan += $tunjanganHarian;
            }
        }
        
        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        $gajiBersih = $gajiKotor - $pajakBulanan;

        $payroll = Payroll::updateOrCreate(
            ['user_id' => $userId, 'month' => $month, 'year' => $year],
            [
                'total_gaji_pokok' => $totalGajiPokok,
                'total_tunjangan' => $totalTunjangan,
                'total_potongan' => $totalPotongan + $pajakBulanan,
                'pajak' => $pajakBulanan,
                'gaji_bersih' => $gajiBersih,
            ]
        );

        return response()->json(['message' => 'Slip gaji berhasil dibuat.', 'data' => $payroll]);
    }

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
}