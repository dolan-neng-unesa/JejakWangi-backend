<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Dipakai bersama 'auth:sanctum' untuk endpoint yang cuma boleh diakses
// dashboard admin (svarga-admin) — mis. daftar pengguna, notifikasi
// sistem, buat zona geofencing. Endpoint baca yang aman dilihat publik
// (sensor, koridor, festival, dst.) TIDAK memakai middleware ini.
//
// Admin yang dinonaktifkan (is_active=false) juga ditolak: tanpa cek ini,
// token yang masih tersisa tetap bisa dipakai mengelola dashboard.
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (!$user?->is_admin || !$user->is_active) {
            return response()->json(['message' => 'Endpoint ini khusus admin.'], 403);
        }

        return $next($request);
    }
}
