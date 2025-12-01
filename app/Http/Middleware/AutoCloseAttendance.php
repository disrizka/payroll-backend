<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutoCloseAttendance
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 1️⃣ Ambil tanggal terakhir command dijalankan dari cache
        $lastRun = Cache::get('auto_close_last_run');
        
        // 2️⃣ Ambil tanggal hari ini
        $today = Carbon::today()->toDateString(); // Format: 2024-01-15
        
        // 3️⃣ Cek: Apakah hari ini sudah pernah jalan?
        if ($lastRun !== $today) {
            // Belum jalan hari ini, maka jalankan command
            
            Log::info('🔄 Auto-close attendance dimulai oleh: ' . ($request->user()->name ?? 'Guest'));
            
            // 4️⃣ Panggil command attendance:auto-close
            Artisan::call('attendance:auto-close');
            
            // 5️⃣ Simpan tanggal hari ini ke cache (berlaku sampai besok)
            Cache::put('auto_close_last_run', $today, now()->addDay());
            
            Log::info('✅ Auto-close attendance selesai!');
        }
        
        // 6️⃣ Lanjutkan request ke controller (user gak terganggu)
        return $next($request);
    }
}