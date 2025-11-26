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
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:izin,cuti',
            'reason' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location' => 'nullable|string',
            'file_proof' => 'nullable|file|mimes:pdf,doc,docx|max:2048',
        ]);

        $userId = Auth::id();
        $start = $request->start_date;
        $end   = $request->end_date;


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

        $existingAttendance = Attendance::where('user_id', $userId)
            ->whereBetween('date', [$start, $end])
            ->exists();

        if ($existingAttendance) {
            return response()->json([
                'message' => 'Tidak bisa mengajukan izin/cuti pada tanggal yang sudah memiliki absensi.'
            ], 400);
        }


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


        $filePath = null;
        if ($request->hasFile('file_proof')) {
            $filePath = $request->file('file_proof')->store('proofs', 'public');
        }


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
}
