<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Beda dari UserResource biasa (dipakai response login/register) —
// resource ini khusus tabel "Daftar Akun Pengguna Jejak Wangi" di dashboard
// admin, field-nya disesuaikan dengan tampilan tabel itu.
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'email' => $this->email,
            'peran' => $this->is_admin ? 'Admin' : 'User',
            'is_admin' => (bool) $this->is_admin,
            'umkm_id' => $this->umkm_id ? (int) $this->umkm_id : null,
            'is_active' => (bool) $this->is_active,
            'status' => $this->is_active ? 'Aktif' : 'NonAktif',
            'bergabung' => $this->created_at ? self::formatTanggalIndonesia($this->created_at) : null,
        ];
    }

    /** Format tanggal manual ke Indonesia, tidak bergantung locale server (APP_LOCALE default 'en'). */
    private static function formatTanggalIndonesia($date): string
    {
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $date->day . ' ' . $bulan[$date->month - 1] . ' ' . $date->year;
    }
}
