<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoClosePendingAttendance extends Command
{
    protected $signature = 'attendance:auto-close';
    protected $description = 'Close attendance & update payroll untuk SEMUA karyawan';

    private const GAJI_POKOK_HARIAN = 50000;
    private const TUNJANGAN_HARIAN = 25000;
    private const PAJAK_BULANAN = 100000;

    public function handle()
    {
        $this->info("🕐 " . Carbon::now()->format('d M Y H:i:s'));
        
        // 🔥 STEP 1: Close attendance yang belum checkout
        $this->closeUncheckedAttendances();
        
        // 🔥 STEP 2: Update payroll SEMUA karyawan
        $this->updateAllEmployeesPayroll();

        return Command::SUCCESS;
    }

    /**
     * Close attendance yang belum checkout hari ini
     */
    private function closeUncheckedAttendances()
    {
        $pendingAttendances = Attendance::with('user')
            ->whereNull('check_out_time')
            ->whereDate('date', Carbon::today())
            ->get();

        if ($pendingAttendances->isEmpty()) {
            $this->info('✅ Semua karyawan sudah checkout hari ini');
            return;
        }

        $this->info("🔄 Ditemukan {$pendingAttendances->count()} karyawan belum checkout");

        foreach ($pendingAttendances as $attendance) {
            $user = $attendance->user;
            
            if (!$user) {
                $this->error("   ⚠️  User ID {$attendance->user_id} tidak ditemukan!");
                continue;
            }

            $date = Carbon::parse($attendance->date);

            $this->warn("   ⚠️  {$user->name} ({$user->nik}) - Ditandai Alpha");

            // Tandai sebagai Alpha
            $attendance->update([
                'status_check_out' => 'Tidak Hadir',
                'potongan_check_out' => 0,
            ]);
        }

        $this->info('');
    }

    /**
     * 🔥 Update payroll untuk SEMUA karyawan (termasuk cuti, izin, alpha)
     */
    private function updateAllEmployeesPayroll()
    {
        $employees = User::where('role', 'karyawan')->get();
        
        $this->info("💰 Memproses payroll untuk {$employees->count()} karyawan...\n");

        foreach ($employees as $employee) {
            $this->info("👤 {$employee->name} ({$employee->nik})");
            
            $this->updatePayrollForMonth($employee->id, Carbon::now()->year, Carbon::now()->month);
            
            $this->info("   ✅ Payroll updated\n");
        }

        $this->info("✅ Selesai! Total karyawan: {$employees->count()}");
    }

    /**
     * Update payroll bulan ini untuk 1 karyawan
     */
    private function updatePayrollForMonth($userId, $year, $month)
    {
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

        // ✅ FIX: Query yang menangkap cuti lintas bulan
        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where(function($query) use ($startDate, $endDate) {
                $query->where(function($q) use ($startDate, $endDate) {
                    // Cuti yang mulai di bulan ini
                    $q->whereBetween('start_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    // Cuti yang berakhir di bulan ini (PENTING untuk cuti lintas bulan!)
                    $q->whereBetween('end_date', [$startDate, $endDate]);
                })
                ->orWhere(function($q) use ($startDate, $endDate) {
                    // Cuti yang melewati seluruh bulan ini
                    $q->where('start_date', '<=', $startDate)
                      ->where('end_date', '>=', $endDate);
                });
            })
            ->get();

        // Loop setiap hari di bulan ini (sampai hari ini)
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

            // 🟢 Cek 1: Ada cuti/izin?
            if ($leave) {
                if ($leave->type === 'cuti') {
                    $gajiPokok = self::GAJI_POKOK_HARIAN;
                    $tunjangan = 0;
                    $status = 'cuti';
                } else {
                    $gajiPokok = 0;
                    $tunjangan = 0;
                    $status = 'izin';
                }
            } 
            // 🟢 Cek 2: Ada attendance?
            elseif ($attendance) {
                $checkIn = $attendance->check_in_time ? Carbon::parse($attendance->check_in_time)->format('H:i:s') : '-';
                $checkOut = $attendance->check_out_time ? Carbon::parse($attendance->check_out_time)->format('H:i:s') : '-';

                // Sudah checkout
                if ($attendance->check_out_time) {
                    $potonganCheckIn = $attendance->potongan_check_in ?? 0;
                    $potonganCheckOut = $attendance->potongan_check_out ?? 0;

                    if ($attendance->status_check_in === 'Alpha' || $attendance->status_check_out === 'Tidak Hadir') {
                        $gajiPokok = 0;
                        $tunjangan = 0;
                        $potongan = 0;
                        $status = 'alpha';
                    } else {
                        $gajiPokok = self::GAJI_POKOK_HARIAN;
                        $tunjangan = self::TUNJANGAN_HARIAN;
                        $potongan = $potonganCheckIn + $potonganCheckOut;
                        $status = 'hadir';
                    }
                } 
                // Belum checkout (sudah di-close jadi "Tidak Hadir")
                else {
                    if ($attendance->status_check_out === 'Tidak Hadir') {
                        $status = 'alpha';
                        $gajiPokok = 0;
                        $tunjangan = 0;
                    }
                }
            }
            // 🔴 Cek 3: Tidak ada apa-apa (alpha)
            else {
                $status = 'alpha';
                $gajiPokok = 0;
                $tunjangan = 0;
            }

            $totalGajiPokok += $gajiPokok;
            $totalTunjangan += $tunjangan;
            $totalPotonganHarian += $potongan;

            $detailPerHari[] = [
                'tanggal' => $dateStr,
                'status' => $status,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'gaji_harian' => $gajiPokok,
                'tunjangan_harian' => $tunjangan,
                'potongan_harian' => $potongan,
            ];
        }

        $gajiKotor = $totalGajiPokok + $totalTunjangan;
        
        // 🔥 FIX: Pajak dipotong berdasarkan bulan payroll, bukan hari ini
        $pajak = 0;
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();
        if (Carbon::now()->greaterThanOrEqualTo($monthEnd) && $gajiKotor > 0) {
            $pajak = self::PAJAK_BULANAN;
        }
        
        $totalSemuaPotongan = $totalPotonganHarian + $pajak;
        $gajiBersih = max(0, $gajiKotor - $totalSemuaPotongan);

        // 💾 SIMPAN KE DATABASE
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
}