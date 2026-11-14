<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// Tambahkan pemanggilan ini ke database/seeders/DatabaseSeeder.php bawaan Laravel,
// di dalam method run().
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UmkmSeeder::class,
            FestivalSeeder::class,
            SensorReadingSeeder::class,
            SoundscapeSeeder::class,
            GeofenceSeeder::class,
            MoodLogSeeder::class,
            GreenSpaceSeeder::class,
            KoridorSeeder::class,
            AdminUserSeeder::class,
            AdminNotificationSeeder::class,
            ChargerStationSeeder::class,
            KasirDemoSeeder::class,
<<<<<<< HEAD
            ReportsWasteSeeder::class,
=======
>>>>>>> 0e65e5b26d27c7d03831a1380f3391c670d64ff1
        ]);
    }
}
