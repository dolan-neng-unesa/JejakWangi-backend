<?php

namespace Database\Seeders;

use App\Models\SoundscapeModel;
use Illuminate\Database\Seeder;

class SoundscapeSeeder extends Seeder
{
    public function run(): void
    {
        SoundscapeModel::query()->delete();

        SoundscapeModel::insert([
            ['id' => 1, 'title' => 'Gending Using Sore Hari', 'category' => 'gamelan_using', 'duration' => '05:10', 'audio_url' => '/audio/gending-using-sore.mp3', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'title' => 'Angklung Paglak', 'category' => 'gamelan_using', 'duration' => '03:45', 'audio_url' => '/audio/angklung-paglak.mp3', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'title' => 'Alam yang Tenang', 'category' => 'alam', 'duration' => '04:20', 'audio_url' => '/audio/alam-yang-tenang.mp3', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
