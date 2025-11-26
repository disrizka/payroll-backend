<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function createEmployee(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'nik' => 'required|string|max:255|unique:users',
            'jabatan' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'status' => 'required|in:aktif,tidak_aktif',
        ]);

        $user = User::create([
            'name' => $request->name,
            'nik' => $request->nik,
            'jabatan' => $request->jabatan,
            'password' => Hash::make($request->password),
            'role' => 'karyawan',
            'status' => $request->status,
        ]);

        return response()->json(['message' => 'Karyawan berhasil dibuat', 'user' => $user], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'nik' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('nik', $request->nik)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'NIK atau Password salah.'], 401);
        }

        if ($user->status !== 'aktif') {
            return response()->json(['message' => 'Akun Anda tidak aktif.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }
     public function user(Request $request)
    {
        return response()->json($request->user());
    }
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Password saat ini salah.'], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password berhasil diubah.']);
    }
        public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    }
}