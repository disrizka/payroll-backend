<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    /**
     * Menyimpan pengajuan izin/cuti baru dari karyawan.
     */
    public function store(Request $request)
    {
        // Validasi input dari request
        $request->validate([
            'type' => 'required|in:izin,cuti',
            'reason' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location' => 'nullable|string',
            'file_proof' => 'nullable|file|mimes:pdf,doc,docx|max:2048', // File opsional
        ]);

        // --- VALIDASI BARU ---
        // Cek apakah sudah ada catatan absensi di rentang tanggal yang diajukan.
        $existingAttendance = Attendance::where('user_id', Auth::id())
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->exists();

        if ($existingAttendance) {
            return response()->json(['message' => 'Anda tidak bisa mengajukan izin/cuti pada tanggal yang sudah memiliki catatan absensi.'], 400);
        }
        // --- AKHIR VALIDASI BARU ---

        $filePath = null;
        if ($request->hasFile('file_proof')) {
            // Simpan file ke storage/app/public/proofs
            $filePath = $request->file('file_proof')->store('proofs', 'public');
        }

        // Buat record pengajuan baru di database
        LeaveRequest::create([
            'user_id' => Auth::id(),
            'type' => $request->type,
            'reason' => $request->reason,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => 'pending', // Status default
            'location' => $request->location,
            'file_proof' => $filePath,
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil dikirim.',
        ], 201);
    }
}