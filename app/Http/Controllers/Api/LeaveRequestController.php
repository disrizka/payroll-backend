<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class LeaveRequestController extends Controller
{
    // ===========================
    //  STORE - Pengajuan Cuti/Izin
    // ===========================
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:izin,cuti',
            'reason' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location' => 'nullable|string',
            'file_proof' => 'nullable|file|mimes:pdf,doc,docx|max:20480',
        ]);

        $userId = Auth::id();
        $start = $request->start_date;
        $end   = $request->end_date;

        // 1️⃣ Validasi jumlah cuti per tahun
        if ($request->type === 'cuti') {

            $year = Carbon::parse($start)->year;

            $cutiAktif = LeaveRequest::where('user_id', $userId)
                ->where('type', 'cuti')
                ->whereYear('start_date', $year)
                ->whereIn('status', ['approved', 'pending'])
                ->get();

            $totalHariCuti = 0;

            foreach ($cutiAktif as $c) {
                $startC = Carbon::parse($c->start_date);
                $endC   = Carbon::parse($c->end_date);
                $totalHariCuti += $startC->diffInDays($endC) + 1;
            }

            $hariPengajuanBaru = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

            if (($totalHariCuti + $hariPengajuanBaru) > 12) {
                return response()->json([
                    'message' => 'Total hari cuti Anda telah melebihi batas 12 hari/tahun. 
                                  Sisa hari cuti: ' . max(0, 12 - $totalHariCuti)
                ], 400);
            }
        }

        // 2️⃣ Cek bentrok dengan absensi
        $existingAttendance = Attendance::where('user_id', $userId)
            ->whereBetween('date', [$start, $end])
            ->exists();

        if ($existingAttendance) {
            return response()->json([
                'message' => 'Tidak bisa mengajukan izin/cuti pada tanggal yang sudah memiliki absensi.'
            ], 400);
        }

        // 3️⃣ Cek bentrok dengan pengajuan lama
        $existingLeave = LeaveRequest::where('user_id', $userId)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('end_date', [$start, $end])
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('start_date', '<=', $start)
                          ->where('end_date', '>=', $end);
                    });
            })
            ->exists();

        if ($existingLeave) {
            return response()->json([
                'message' => 'Tanggal pengajuan bentrok dengan izin/cuti yang sudah ada.'
            ], 400);
        }

        // 4️⃣ Upload file (opsional)
        $filePath = null;
        if ($request->hasFile('file_proof')) {
            $filePath = $request->file('file_proof')->store('proofs', 'public');
        }

        // 5️⃣ Simpan pengajuan
        LeaveRequest::create([
            'user_id' => $userId,
            'type' => $request->type,
            'reason' => $request->reason,
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'pending',
            'location' => $request->location,
            'file_proof' => $filePath,
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil dikirim.'
        ], 201);
    }

    // ===========================
    //  INDEX - Semua Leave Requests (untuk admin)
    // ===========================
    public function index()
    {
        try {
            $leaves = LeaveRequest::with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                "success" => true,
                "data" => $leaves
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Gagal mengambil data pengajuan",
                "error" => $e->getMessage(),
            ], 500);
        }
    }

    // ===========================
    //  GET PENDING - Pengajuan yang Pending
    // ===========================
    public function getPending()
    {
        try {
            $leaves = LeaveRequest::with('user:id,name,email')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                "success" => true,
                "data" => $leaves
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Gagal mengambil data pengajuan pending",
                "error" => $e->getMessage(),
            ], 500);
        }
    }

    // ===========================
    //  MY LEAVE REQUESTS - Pengajuan Milik User
    // ===========================
    public function myLeaveRequests()
    {
        try {
            $userId = Auth::id();
            
            $leaves = LeaveRequest::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                "success" => true,
                "data" => $leaves
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Gagal mengambil data pengajuan Anda",
                "error" => $e->getMessage(),
            ], 500);
        }
    }

    // ===========================
    //  APPROVE - Setujui Cuti/Izin
    // ===========================
    public function approve($id)
    {
        try {
            // 1️⃣ Ambil data cuti/izin
            $leave = LeaveRequest::findOrFail($id);

            // 2️⃣ Update status jadi approved
            $leave->status = "approved";
            $leave->save();

            // 3️⃣ Loop tanggal dari start sampai end
            $start = Carbon::parse($leave->start_date);
            $end   = Carbon::parse($leave->end_date);

            while ($start->lte($end)) {

                Attendance::create([
                    'user_id'            => $leave->user_id,
                    'date'               => $start->format('Y-m-d'),

                    // Kolom absensi dibuat NULL, karena cuti/izin tidak absen
                    'check_in_time'      => null,
                    'check_out_time'     => null,
                    'check_in_location'  => null,
                    'check_out_location' => null,

                    // Status cuti/izin
                    'status_check_in'    => ucfirst($leave->type),
                    'status_check_out'   => ucfirst($leave->type),

                    'potongan_check_in'  => 0,
                    'potongan_check_out' => 0,
                ]);

                $start->addDay();
            }

            return response()->json([
                "success" => true,
                "message" => "Leave approved successfully"
            ]);

        } catch (\Exception $e) {

            return response()->json([
                "success" => false,
                "message" => "Gagal menyetujui pengajuan",
                "error"   => $e->getMessage(),
            ], 500);
        }
    }

    // ===========================
    //  REJECT - Tolak Cuti/Izin
    // ===========================
    public function reject($id)
    {
        try {
            // 1️⃣ Ambil data cuti/izin
            $leave = LeaveRequest::findOrFail($id);

            // 2️⃣ Update status jadi rejected
            $leave->status = "rejected";
            $leave->save();

            return response()->json([
                "success" => true,
                "message" => "Leave rejected successfully"
            ]);

        } catch (\Exception $e) {

            return response()->json([
                "success" => false,
                "message" => "Gagal menolak pengajuan",
                "error"   => $e->getMessage(),
            ], 500);
        }
    }
}