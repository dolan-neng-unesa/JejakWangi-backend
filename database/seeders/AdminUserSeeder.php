<?php

namespace Database\Seeders;

use App\Models\UserModel;
use Illuminate\Database\Seeder;

// Menggantikan AdminUser (Prisma/Supabase) yang sebelumnya dipakai login
// jejak-wangi-admin. Di lingkungan lokal/test kredensial demo tetap admin@jejakwangi.id / jejakwangi123.
//
// Keamanan:
//  - Di produksi WAJIB mengisi ADMIN_SEED_PASSWORD (dan opsional ADMIN_SEED_EMAIL) di .env;
//    seeder menolak berjalan dengan sandi demo.
//  - Sandi HANYA diatur saat akun dibuat. Versi lama memakai updateOrCreate sehingga setiap
//    `db:seed` (mis. saat deploy) mereset sandi admin kembali ke sandi demo.
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL', 'admin@jejakwangi.id');
        $password = env('ADMIN_SEED_PASSWORD');

        if (!$password) {
            if (app()->isProduction()) {
                throw new \RuntimeException('Set ADMIN_SEED_PASSWORD di .env sebelum menjalankan AdminUserSeeder di produksi.');
            }
            $password = 'jejakwangi123';
        }

        $admin = UserModel::firstOrNew(['email' => $email]);
        if (!$admin->exists) {
            $admin->name = 'Ahmad Fauzi';
            $admin->password = $password; // cast 'hashed' di UserModel
        }
        $admin->is_admin = true;
        $admin->is_active = true;
        $admin->save();
    }
}
