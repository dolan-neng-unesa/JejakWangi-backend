<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Dipasang setelah 'auth:sanctum': akun yang dinonaktifkan admin tidak boleh
// memakai token apa pun (login baru juga ditolak di AuthController@login).
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user && !$user->is_active) {
            return response()->json(['message' => 'Akun ini dinonaktifkan. Hubungi administrator.'], 403);
        }

        return $next($request);
    }
}
