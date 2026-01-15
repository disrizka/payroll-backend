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
use Barryvdh\DomPDF\Facade\Pdf; // 👈 Tambahkan ini

class PayrollController extends Controller
{
   
    public function generate($userId, $year, $month)
    {
        $user = User::findOrFail($userId);

        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

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
            ->keyBy(fn($i) => Carbon::parse($i->date)->toDateString());

        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->get();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

            $dateStr = $date->toDateString();
            $status = "Alpha";
            $gajiHariIni = 0;
            $tunjanganHariIni = 0;
            $potonganHariIni = 0;

            $leaveToday = $leaves->first(function($l) use ($date) {
                return $date->between(Carbon::parse($l->start_date), Carbon::parse($l->end_date));
            });

            if ($leaveToday) {

                if ($leaveToday->type === 'cuti') {
                    $status = "Cuti";
                    $gajiHariIni = $gajiPokokHarian;
                } else {
                    $status = "Izin";
                }

            } else if ($attendances->has($dateStr)) {

                $attendance = $attendances[$dateStr];

                $status = "Hadir";
                $gajiHariIni = $gajiPokokHarian;

                $potIn = $attendance->potongan_check_in ?? 0;
                $potOut = $attendance->potongan_check_out ?? 0;

                $potonganHariIni = $potIn + $potOut;

                $gajiHariIni -= $potonganHariIni;

                if ($attendance->check_in_time && $attendance->check_out_time) {
                    $tunjanganHariIni = $tunjanganHarian;
                }

            }

            $totalGajiPokok += $gajiHariIni;
            $totalTunjangan += $tunjanganHariIni;
            $totalPotonganHarian += $potonganHariIni;

            $detailPerHari[] = [
                'tanggal' => $dateStr,
                'status' => $status,
                'gaji_harian' => $gajiHariIni,
                'tunjangan_harian' => $tunjanganHariIni,
                'potongan_harian' => $potonganHariIni,
                'pendapatan_hari_itu' => $gajiHariIni + $tunjanganHariIni,
            ];
        }

        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        
        $pajakDipotong = $gajiKotor > 0 ? $pajakBulanan : 0;
        $totalSemuaPotongan = $totalPotonganHarian + $pajakDipotong;
        
        $gajiBersih = max(0, $gajiKotor - $totalSemuaPotongan);

        $payroll = Payroll::updateOrCreate(
            ['user_id' => $userId, 'month' => $month, 'year' => $year],
            [
                'total_gaji_pokok' => $totalGajiPokok,
                'total_tunjangan' => $totalTunjangan,
                'total_potongan' => $totalSemuaPotongan,
                'pajak' => $pajakDipotong, 
                'gaji_bersih' => $gajiBersih,
                'detail' => json_encode($detailPerHari)
            ]
        );

        return response()->json(['message' => 'Slip gaji berhasil dibuat.', 'data' => $payroll]);
    }

    // 🔥 METHOD BARU: Generate PDF untuk Karyawan
    public function generatePdfForEmployee($year, $month)
    {
        try {
            $user = Auth::user();
            
            // Ambil data slip gaji dari endpoint live
            $controller = new AttendanceController();
            $response = $controller->calculateLivePayslip($year, $month);
            $responseData = $response->getData();
            
            if (!$responseData->success) {
                return response()->json([
                    'message' => 'Gagal mengambil data slip gaji'
                ], 404);
            }
            
            $slipGaji = $responseData->data;
            
            // Format periode
            $periode = Carbon::create($year, $month, 1)->locale('id')->isoFormat('MMMM YYYY');
            
            // Generate PDF
            $pdf = Pdf::loadView('pdf.slip-gaji', [
                'slipGaji' => $slipGaji,
                'periode' => $periode,
                'user' => $user,
                'tanggalCetak' => Carbon::now()->locale('id')->isoFormat('D MMMM YYYY')
            ]);
            
            // Set paper size dan orientation
            $pdf->setPaper('a4', 'portrait');
            
            // Return PDF sebagai download
            return $pdf->download("Slip_Gaji_{$periode}.pdf");
            
        } catch (\Exception $e) {
            \Log::error('Error generating PDF: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal membuat PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // 🔥 METHOD BARU: Stream PDF untuk preview
    public function streamPdfForEmployee($year, $month)
    {
        try {
            $user = Auth::user();
            
            // Ambil data slip gaji
            $controller = new AttendanceController();
            $response = $controller->calculateLivePayslip($year, $month);
            $responseData = $response->getData();
            
            if (!$responseData->success) {
                return response()->json([
                    'message' => 'Gagal mengambil data slip gaji'
                ], 404);
            }
            
            $slipGaji = $responseData->data;
            $periode = Carbon::create($year, $month, 1)->locale('id')->isoFormat('MMMM YYYY');
            
            // Generate PDF
            $pdf = Pdf::loadView('pdf.slip-gaji', [
                'slipGaji' => $slipGaji,
                'periode' => $periode,
                'user' => $user,
                'tanggalCetak' => Carbon::now()->locale('id')->isoFormat('D MMMM YYYY')
            ]);
            
            $pdf->setPaper('a4', 'portrait');
            
            // Return PDF untuk preview (stream inline)
            return $pdf->stream("Slip_Gaji_{$periode}.pdf");
            
        } catch (\Exception $e) {
            \Log::error('Error streaming PDF: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal membuat PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}