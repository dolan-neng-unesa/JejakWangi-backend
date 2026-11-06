<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'device' => \App\Http\Middleware\AuthenticateDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            // Tanpa cabang ini, AuthenticationException (butuh login/token) dan
            // ValidationException akan ikut kena fallback 500 di bawah — jadi
            // route yang memakai middleware auth:sanctum (mis. /api/auth/me,
            // /api/auth/logout) tidak pernah bisa membalas 401 yang benar.
            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => 'Perlu login untuk mengakses endpoint ini.'], 401);
            }
            if ($e instanceof ValidationException) {
                return response()->json(['message' => 'Data tidak valid.', 'errors' => $e->errors()], 422);
            }
            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json(['message' => 'Data tidak ditemukan.'], 404);
            }
            // 429 (throttle), 405, dst. harus tetap dengan status aslinya — ESP32
            // membaca 429 + Retry-After untuk tahu kapan boleh mengirim lagi.
            if ($e instanceof HttpExceptionInterface) {
                return response()->json(['message' => $e->getMessage() ?: 'Permintaan ditolak.'], $e->getStatusCode(), $e->getHeaders());
            }
            return response()->json(['message' => config('app.debug') ? $e->getMessage() : 'Terjadi kesalahan pada server.',], 500);
        });
    })->create();
