<?php

namespace Database\Seeders;

use App\Models\KoridorModel;
use Illuminate\Database\Seeder;

// Data disamakan persis dengan koridors[] di
// svarga-app/src/data/mockGreenSpaces.js — termasuk sensor_snapshot, supaya
// perilaku FE (termasuk kartu "Kondisi Lingkungan" di HomePage) tidak
// berubah saat berpindah dari mock/Firestore ke API ini. Gambar (thumbnail/
// hero_image) menunjuk ke public/images/koridor/ (lihat KoridorResource
// untuk cara jadi URL absolut).
class KoridorSeeder extends Seeder
{
    public function run(): void
    {
        KoridorModel::query()->delete();

        $rows = [
            [
                'id' => 1,
                'green_space_id' => 1,
                'short_name' => 'RTH Sritanjung',
                'formal_name' => 'Koridor 1: Sritanjung',
                'route_label' => 'Taman Sritanjung - Kampung Melayu',
                'desc' => 'Taman Sritanjung asri dan kuliner',
                'thumbnail' => 'images/koridor/thumb-sritanjung.png',
                'hero_image' => 'images/koridor/hero-koridor1.png',
                'hijau_level' => 'paling_hijau',
                'hijau_score' => 5,
                'umkm_count' => 5,
                'status' => 'pilot',
                'distance_meter' => 400,
                'estimate_minutes' => 6,
                // Koordinat perkiraan (awal = Taman Sritanjung, akhir = Taman Blambangan); ganti dengan hasil survei.
                'path' => [[-8.21944, 114.36972], [-8.21833, 114.36951], [-8.21748, 114.36869], [-8.2175, 114.3675]],
                'shade_score' => 82,
                'oxygen_kg_day' => 11.7,
                'co2_reduced_kg_day' => 16,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'mahoni', 'jumlah' => 18], ['jenis' => 'tanjung', 'jumlah' => 12], ['jenis' => 'angsana', 'jumlah' => 10]], 'kendaraanPerHari' => ['motor' => 1200, 'mobil' => 150], 'toko' => [['nama' => 'UMKM A', 'unitAC' => 1, 'kWPerUnit' => 0.8, 'jamOperasi' => 10, 'faktorBeban' => 0.7], ['nama' => 'UMKM B', 'unitAC' => 1, 'kWPerUnit' => 0.8, 'jamOperasi' => 10, 'faktorBeban' => 0.7]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Koridor ini memiliki cakupan pohon yang rimbun dan kualitas udara paling bersih di pusat kota. Dilengkapi infrastruktur pedestrian teduh.',
                'sensor' => ['pm25' => 12, 'pm25_status' => 'Sehat', 'suhu' => 26.4, 'suhu_status' => 'Sejuk', 'kelembaban' => 65, 'kelembaban_status' => 'Nyaman', 'kebisingan' => 52, 'kebisingan_status' => 'Tenang', 'uv_index' => 2.1, 'uv_status' => 'Rendah', 'sensor_status' => 'online'],
            ],
            [
                'id' => 2,
                'green_space_id' => null,
                'short_name' => 'Corridor 1 Kalilo',
                'formal_name' => 'Koridor 2: Kalilo',
                'route_label' => 'Kalilo - Jembatan Merah',
                'desc' => 'Kawasan sungai dan spot foto',
                'thumbnail' => 'images/koridor/thumb-kalilo.png',
                'hero_image' => 'images/koridor/thumb-kalilo.png',
                'hijau_level' => 'agak_hijau',
                'hijau_score' => 4,
                'umkm_count' => 8,
                'status' => 'rencana',
                'distance_meter' => 620,
                'estimate_minutes' => 9,
                // koordinat perkiraan, ganti dengan hasil survei / gambar di mode edit peta (/map?edit=1).
                'path' => [[-8.2108, 114.3702], [-8.2102, 114.3724], [-8.2095, 114.3742], [-8.209, 114.3758]],
                'shade_score' => 64,
                'oxygen_kg_day' => 9.1,
                'co2_reduced_kg_day' => 12.5,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'mahoni', 'jumlah' => 14], ['jenis' => 'angsana', 'jumlah' => 20], ['jenis' => 'tanjung', 'jumlah' => 6]], 'kendaraanPerHari' => ['motor' => 1800, 'mobil' => 260], 'toko' => [['nama' => 'Kafe', 'unitAC' => 2, 'kWPerUnit' => 0.8, 'jamOperasi' => 12, 'faktorBeban' => 0.7], ['nama' => 'UMKM', 'unitAC' => 1, 'kWPerUnit' => 0.75, 'jamOperasi' => 10, 'faktorBeban' => 0.7]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Koridor menyusuri tepi sungai dengan pepohonan sedang. Ramai dikunjungi sore hari untuk berfoto dan bersantai.',
                'sensor' => ['pm25' => 24, 'pm25_status' => 'Sedang', 'suhu' => 28.1, 'suhu_status' => 'Hangat', 'kelembaban' => 58, 'kelembaban_status' => 'Nyaman', 'kebisingan' => 61, 'kebisingan_status' => 'Ramai', 'uv_index' => 4.3, 'uv_status' => 'Sedang', 'sensor_status' => 'online'],
            ],
            [
                'id' => 3,
                'green_space_id' => null,
                'short_name' => 'Corridor 2 Perliman',
                'formal_name' => 'Koridor 3: Perliman',
                'route_label' => 'Simpang Lima - Terminal',
                'desc' => 'Kawasan padat lalu lintas perkotaan',
                'thumbnail' => 'images/koridor/thumb-perliman.png',
                'hero_image' => 'images/koridor/thumb-perliman.png',
                'hijau_level' => 'setengah_hijau',
                'hijau_score' => 3,
                'umkm_count' => 3,
                'status' => 'rencana',
                'distance_meter' => 510,
                'estimate_minutes' => 8,
                // koordinat perkiraan, ganti dengan hasil survei / gambar di mode edit peta (/map?edit=1).
                'path' => [[-8.2228, 114.3612], [-8.2239, 114.363], [-8.2252, 114.3646], [-8.2262, 114.366]],
                'shade_score' => 41,
                'oxygen_kg_day' => 3.3,
                'co2_reduced_kg_day' => 4.5,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'angsana', 'jumlah' => 15], ['jenis' => 'mahoni', 'jumlah' => 5]], 'kendaraanPerHari' => ['motor' => 4200, 'mobil' => 900, 'bus' => 40, 'truk' => 25], 'toko' => [['nama' => 'Minimarket', 'unitAC' => 3, 'kWPerUnit' => 0.9, 'jamOperasi' => 16, 'faktorBeban' => 0.8]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Koridor padat lalu lintas dengan pohon peneduh terbatas. Disarankan berjalan pada jam bukan puncak.',
                'sensor' => ['pm25' => 38, 'pm25_status' => 'Sedang', 'suhu' => 30.2, 'suhu_status' => 'Panas', 'kelembaban' => 52, 'kelembaban_status' => 'Kering', 'kebisingan' => 74, 'kebisingan_status' => 'Bising', 'uv_index' => 6.8, 'uv_status' => 'Tinggi', 'sensor_status' => 'online'],
            ],
            [
                'id' => 4,
                'green_space_id' => 2,
                'short_name' => 'Koridor 3 Blambangan',
                'formal_name' => 'Koridor 4: Blambangan',
                'route_label' => 'Taman Blambangan - GOR',
                'desc' => 'Kawasan hijau dan pusat kuliner',
                'thumbnail' => 'images/koridor/thumb-blambangan.png',
                'hero_image' => 'images/koridor/thumb-blambangan.png',
                'hijau_level' => 'paling_hijau',
                'hijau_score' => 5,
                'umkm_count' => 6,
                'status' => 'rencana',
                'distance_meter' => 350,
                'estimate_minutes' => 5,
                // koordinat perkiraan, ganti dengan hasil survei / gambar di mode edit peta (/map?edit=1).
                'path' => [[-8.2175, 114.3675], [-8.219, 114.3662], [-8.2206, 114.365], [-8.222, 114.3638]],
                'shade_score' => 79,
                'oxygen_kg_day' => 16.2,
                'co2_reduced_kg_day' => 22.3,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'mahoni', 'jumlah' => 25], ['jenis' => 'tanjung', 'jumlah' => 14], ['jenis' => 'flamboyan', 'jumlah' => 6]], 'kendaraanPerHari' => ['motor' => 900, 'mobil' => 120], 'toko' => [['nama' => 'Kantin', 'unitAC' => 1, 'kWPerUnit' => 0.75, 'jamOperasi' => 8, 'faktorBeban' => 0.6]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Kawasan taman kota dengan kanopi pohon luas dan banyak pilihan kuliner di sekelilingnya.',
                'sensor' => ['pm25' => 15, 'pm25_status' => 'Sehat', 'suhu' => 27.0, 'suhu_status' => 'Sejuk', 'kelembaban' => 63, 'kelembaban_status' => 'Nyaman', 'kebisingan' => 55, 'kebisingan_status' => 'Tenang', 'uv_index' => 2.8, 'uv_status' => 'Rendah', 'sensor_status' => 'online'],
            ],
            [
                'id' => 5,
                'green_space_id' => null,
                'short_name' => 'Corridor 4 Pecinan',
                'formal_name' => 'Koridor 5: Pecinan',
                'route_label' => 'Klenteng - Pasar Lama',
                'desc' => 'Kawasan padat industri dan permukiman',
                'thumbnail' => 'images/koridor/thumb-pecinan.png',
                'hero_image' => 'images/koridor/thumb-pecinan.png',
                'hijau_level' => 'tidak_hijau',
                'hijau_score' => 1,
                'umkm_count' => 4,
                'status' => 'rencana',
                'distance_meter' => 480,
                'estimate_minutes' => 7,
                // koordinat perkiraan, ganti dengan hasil survei / gambar di mode edit peta (/map?edit=1).
                'path' => [[-8.216, 114.3742], [-8.2169, 114.3754], [-8.2178, 114.3765], [-8.2187, 114.3776]],
                'shade_score' => 22,
                'oxygen_kg_day' => 0.3,
                'co2_reduced_kg_day' => 0.5,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'angsana', 'jumlah' => 6], ['jenis' => 'tanjung', 'jumlah' => 3]], 'kendaraanPerHari' => ['motor' => 3800, 'mobil' => 700, 'truk' => 30], 'toko' => [['nama' => 'Toko A', 'unitAC' => 2, 'kWPerUnit' => 0.8, 'jamOperasi' => 10, 'faktorBeban' => 0.7], ['nama' => 'Toko B', 'unitAC' => 2, 'kWPerUnit' => 0.8, 'jamOperasi' => 10, 'faktorBeban' => 0.7]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Kawasan padat bangunan dengan sedikit vegetasi. Kualitas udara perlu dipantau lebih ketat pada jam sibuk.',
                'sensor' => ['pm25' => 52, 'pm25_status' => 'Tidak Sehat', 'suhu' => 31.5, 'suhu_status' => 'Panas', 'kelembaban' => 48, 'kelembaban_status' => 'Kering', 'kebisingan' => 79, 'kebisingan_status' => 'Bising', 'uv_index' => 7.5, 'uv_status' => 'Tinggi', 'sensor_status' => 'online'],
            ],
            [
                'id' => 6,
                'green_space_id' => null,
                'short_name' => 'Corridor 5 Marina Boom',
                'formal_name' => 'Koridor 6: Marina Boom',
                'route_label' => 'Pelabuhan - Pantai Boom',
                'desc' => 'Kawasan padat industri dan permukiman',
                'thumbnail' => 'images/koridor/thumb-marinaboom.png',
                'hero_image' => 'images/koridor/thumb-marinaboom.png',
                'hijau_level' => 'agak_hijau',
                'hijau_score' => 4,
                'umkm_count' => 4,
                'status' => 'rencana',
                'distance_meter' => 610,
                'estimate_minutes' => 9,
                // koordinat perkiraan, ganti dengan hasil survei / gambar di mode edit peta (/map?edit=1).
                'path' => [[-8.2142, 114.3812], [-8.2135, 114.3828], [-8.2128, 114.3845], [-8.2122, 114.3862]],
                'shade_score' => 60,
                'oxygen_kg_day' => 5.7,
                'co2_reduced_kg_day' => 7.8,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'angsana', 'jumlah' => 18], ['jenis' => 'mahoni', 'jumlah' => 8], ['jenis' => 'tanjung', 'jumlah' => 8]], 'kendaraanPerHari' => ['motor' => 1500, 'mobil' => 300, 'bus' => 10, 'truk' => 60], 'toko' => [['nama' => 'Warung', 'unitAC' => 1, 'kWPerUnit' => 0.75, 'jamOperasi' => 8, 'faktorBeban' => 0.6]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Koridor pesisir dengan angin laut sejuk. Vegetasi peneduh sedang, cocok untuk jalan sore.',
                'sensor' => ['pm25' => 20, 'pm25_status' => 'Sehat', 'suhu' => 28.6, 'suhu_status' => 'Hangat', 'kelembaban' => 70, 'kelembaban_status' => 'Lembab', 'kebisingan' => 58, 'kebisingan_status' => 'Tenang', 'uv_index' => 5.1, 'uv_status' => 'Sedang', 'sensor_status' => 'online'],
            ],
            [
                'id' => 7,
                'green_space_id' => null,
                'short_name' => 'Corridor Pasar',
                'formal_name' => 'Koridor Pasar Induk',
                'route_label' => 'Pasar Induk Banyuwangi',
                'desc' => 'Pasar Induk Banyuwangi',
                'thumbnail' => 'images/koridor/thumb-pasar.png',
                'hero_image' => 'images/koridor/hero-pasar.png',
                'hijau_level' => 'agak_hijau',
                'hijau_score' => 4,
                'umkm_count' => 5,
                'status' => 'rencana',
                'distance_meter' => 450,
                'estimate_minutes' => 7,
                // koordinat perkiraan, ganti dengan hasil survei / gambar di mode edit peta (/map?edit=1).
                'path' => [[-8.2138, 114.3718], [-8.2145, 114.3731], [-8.2152, 114.3742], [-8.2158, 114.3752]],
                'shade_score' => 60,
                'oxygen_kg_day' => 6.0,
                'co2_reduced_kg_day' => 8.2,
                // Masukan neraca karbon (CONTOH, ganti dengan hasil survei). FE menghitung ulang oxygen/co2 dari sini.
                'carbon_inputs' => ['pohon' => [['jenis' => 'mahoni', 'jumlah' => 10], ['jenis' => 'angsana', 'jumlah' => 8]], 'kendaraanPerHari' => ['motor' => 2600, 'mobil' => 420, 'truk' => 45], 'toko' => [['nama' => 'Kios Kuliner', 'unitAC' => 1, 'kWPerUnit' => 0.75, 'jamOperasi' => 10, 'faktorBeban' => 0.6]], 'sumberData' => ['kendaraan' => 'ILUSTRASI', 'pohon' => 'ILUSTRASI', 'ac' => 'ILUSTRASI']],
                'condition_title' => 'Kondisi Koridor Terpilih',
                'condition_desc' => 'Koridor pasar tradisional yang ramai bongkar muat pada pagi hari, dengan deretan pedagang kuliner dan pohon peneduh di sepanjang jalur.',
                // Konten halaman detail sesuai mockup "Koridor Pasar Induk". Semua angka ILUSTRASI — ganti dengan data lapangan.
                'highlights' => [
                    'kunjungan' => [
                        ['icon' => 'footprints', 'title' => 'Parkir sekali, jalan 450 m', 'value' => 'Hemat 120 g CO₂', 'note' => 'Setara ±Rp500', 'sdg' => 'SDG 13'],
                        ['icon' => 'bag', 'title' => 'Bawa tas sendiri di 2 lapak', 'value' => '2 kantong plastik berkurang', 'note' => 'Belanja lebih ramah bumi', 'sdg' => 'SDG 12'],
                        ['icon' => 'wind', 'title' => 'Udara saat kamu jalan', 'value' => 'ISPU 45 · Baik', 'note' => 'Jalur 3°C lebih sejuk', 'sdg' => 'SDG 3 & 11'],
                    ],
                    'eco_coach' => 'Besok coba datang sebelum pukul 07.00, pasar belum padat kendaraan bongkar muat.',
                    'dampak' => [
                        'judul' => 'Dampak bersama kita',
                        'periode' => 'Bulan ini',
                        'komunitas' => 'Warga Sritanjung–Blambangan',
                        'co2_kg' => 85,
                        'kantong_plastik' => 1240,
                        'kompos' => '300 kg sampah sayur pasar jadi kompos untuk 40 pohon taman.',
                    ],
                    'pesan' => 'Kontribusi kecil kita, perubahan besar bersama. Terima kasih sudah ikut menjaga kawasan.',
                ],
                'sensor' => ['pm25' => 22, 'pm25_status' => 'Sehat', 'suhu' => 28.9, 'suhu_status' => 'Hangat', 'kelembaban' => 62, 'kelembaban_status' => 'Nyaman', 'kebisingan' => 66, 'kebisingan_status' => 'Ramai', 'uv_index' => 4.8, 'uv_status' => 'Sedang', 'sensor_status' => 'online'],
            ],
        ];

        // Urutan tampil di daftar (id = nomor wilayah ESP32, jadi tidak digeser): Pasar Induk di posisi 2.
        $urutan = [1 => 1, 7 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7];

        foreach ($rows as $row) {
            $sensor = $row['sensor'];
            $sensor['recorded_at'] = now()->toIso8601String();
            unset($row['sensor']);
            $row['path'] = isset($row['path']) ? json_encode($row['path']) : null;
            $row['sort_order'] = $urutan[$row['id']] ?? $row['id'];
            $row['highlights'] = isset($row['highlights']) ? json_encode($row['highlights'], JSON_UNESCAPED_UNICODE) : null;
            $row['carbon_inputs'] = isset($row['carbon_inputs']) ? json_encode($row['carbon_inputs']) : null;
            $row['sensor_snapshot'] = json_encode($sensor);
            $row['created_at'] = now();
            $row['updated_at'] = now();
            KoridorModel::insert($row);
        }
    }
}
