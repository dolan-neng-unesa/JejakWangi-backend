<?php

namespace Database\Seeders;

use App\Models\UmkmModel;
use App\Models\UserModel;
use Illuminate\Database\Seeder;

// Akun kasir DEMO (lokal/test saja) untuk mencoba QR "membawa tempat sendiri".
// Di produksi, admin membuat akun kasir dari dashboard (isi ID UMKM) — seeder ini tidak berjalan.
class KasirDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }
        $umkm = UmkmModel::query()->orderBy('id')->first();
        if (! $umkm) {
            return;
        }
        $kasir = UserModel::firstOrNew(['email' => 'kasir@jejakwangi.id']);
        if (! $kasir->exists) {
            $kasir->name = 'Kasir Demo';
            $kasir->password = 'jejakwangi123'; // di-hash oleh cast 'hashed'
            $kasir->is_active = true;
        }
        $kasir->forceFill(['umkm_id' => $umkm->id])->save();
    }
}
