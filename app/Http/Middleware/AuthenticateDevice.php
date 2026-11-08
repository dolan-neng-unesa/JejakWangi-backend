<?php

namespace App\Http\Middleware;

use App\Models\SensorDeviceModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Autentikasi ESP32: header `X-Device-Key: <kunci>` (atau `Authorization: Bearer <kunci>`).
// device_code & koridor_id diambil dari baris perangkat, BUKAN dari payload —
// jadi satu perangkat tidak bisa menyamar sebagai perangkat/koridor lain.
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Device-Key') ?? $request->bearerToken();

        $device = $key
            ? SensorDeviceModel::where('key_hash', SensorDeviceModel::hashKey($key))->first()
            : null;

        if (!$device || !$device->is_active) {
            return response()->json(['message' => 'Kunci perangkat tidak valid.'], 401);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
