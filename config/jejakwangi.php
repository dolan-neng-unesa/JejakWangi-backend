<?php

// Aturan Jejak Wangi (dipakai ActivityImpact & controller). Angka hitung HARUS sama dengan
// jejak-wangi-app/src/lib/journey.js agar tampilan app & server konsisten.
return [
    // L/100 km dan faktor emisi (kg CO2/liter) — lihat carbonBalance.js (Permen LH 12/2010, IPCC/DEFRA).
    'liter_per_100km' => ['motor' => 2.66, 'mobil' => 11.79],
    'faktor_emisi_bensin' => 2.31,
    'harga_bbm_per_liter' => 10000,
    'kkal_per_km' => 70,
    'poin_per_km' => 30,

    // Batas kewajaran untuk menolak data palsu (jalan kaki/jogging).
    'maks_jarak_m' => 100000,
    'maks_kecepatan_mps' => 4.5,

    // Periode leaderboard & progres kupon = bulan kalender di zona ini (reset otomatis tiap awal bulan).
    'timezone' => 'Asia/Jakarta',

    // PROBLEM 1 — kupon bertingkat. Progres = total km jalan kaki (non-simulasi) di bulan berjalan.
    // Setiap tingkat punya cooldown sendiri sejak diklaim.
    'kupon_cooldown_hari' => 30,
    'kupon_merchant' => 'UMKM Kuliner Lokal',
    'tiers' => [
        1 => ['kode' => 'pelari-mager', 'nama' => 'Pelari Mager', 'target_km' => 1, 'hadiah' => 'Air Mineral 1 pcs', 'berlaku_hari' => 7],
        2 => ['kode' => 'pelari-niat', 'nama' => 'Pelari Niat', 'target_km' => 3, 'hadiah' => 'Air Isotonik 1 pcs', 'berlaku_hari' => 7],
        3 => ['kode' => 'pelari-kalcer', 'nama' => 'Pelari Kalcer', 'target_km' => 5, 'hadiah' => 'Air Isotonik 1 pcs + Jajan 1 pcs', 'berlaku_hari' => 14],
    ],

    // PROBLEM 2 — leaderboard jarak terjauh bulan ini. Badge = tingkat tertinggi yang terpenuhi (urut dari terkecil).
    'leaderboard_maks' => 100,
    'badges' => [
        ['rank_max' => 1, 'kode' => 'top-1', 'label' => 'Top 1'],
        ['rank_max' => 3, 'kode' => 'top-3', 'label' => 'Top 3'],
        ['rank_max' => 5, 'kode' => 'top-5', 'label' => 'Top 5'],
        ['rank_max' => 10, 'kode' => 'top-10', 'label' => 'Top 10'],
    ],

    // PROBLEM 3 — zero-waste: kasir menerbitkan QR sekali pakai; pelanggan memindainya.
    'byo' => [
        'berlaku_menit' => 5,   // masa berlaku QR
        'kurangi_hari' => 1,    // pengurang cooldown per transaksi, untuk tiap kupon yang sedang cooldown
        'maks_per_hari' => 3,   // batas konfirmasi per pelanggan per hari (anti-penyalahgunaan)
    ],

    // Sampah & Pangan — penimbangan di bank sampah/stasiun. Poin = berat bersih (kg) × poin_per_kg jenisnya.
    // Poin baru masuk ke akun setelah pengguna mengonfirmasi hasil timbang di aplikasi.
    'sampah' => [
        'stasiun' => [
            'BSS-001' => 'Sritanjung',
            'BSS-002' => 'Blambangan',
            'BSS-003' => 'Pasar Banyuwangi',
        ],
        'jenis' => [
            'sisa_sayur' => ['label' => 'Sisa sayur', 'grup' => 'organik', 'poin_per_kg' => 10],
            'daun' => ['label' => 'Daun & ranting', 'grup' => 'organik', 'poin_per_kg' => 10],
            'botol_plastik' => ['label' => 'Botol plastik', 'grup' => 'anorganik', 'poin_per_kg' => 40],
            'kertas' => ['label' => 'Kertas', 'grup' => 'anorganik', 'poin_per_kg' => 10],
            'kaleng' => ['label' => 'Kaleng & logam', 'grup' => 'anorganik', 'poin_per_kg' => 50],
            'kaca' => ['label' => 'Kaca', 'grup' => 'anorganik', 'poin_per_kg' => 20],
            'nasi_lauk' => ['label' => 'Nasi & lauk layak', 'grup' => 'pangan', 'poin_per_kg' => 20],
            'roti_kue' => ['label' => 'Roti & kue', 'grup' => 'pangan', 'poin_per_kg' => 20],
            'sayur_buah' => ['label' => 'Sayur & buah layak', 'grup' => 'pangan', 'poin_per_kg' => 20],
        ],
        // Stasiun → koridor tempat sampah/pangan dihitung dalam neraca per jam.
        'stasiun_koridor' => ['BSS-001' => 1, 'BSS-002' => 4, 'BSS-003' => 7],
        'maks_baris' => 10,
        'maks_kg_per_baris' => 200,
        'checkin_berlaku_menit' => 120, // QR lokasi yang dipindai pengguna dianggap aktif selama ini
    ],

    // ------------------------------------------------------------------ MESIN NERACA (flowchart: Server Lokal → Database per jam)
    'engine' => [
        'faktor_grid' => 0.87,                       // kg CO2/kWh, Jamali (sama dengan carbonBalance.js)
        'faktor_bbm' => ['bensin' => 2.31, 'solar' => 2.68],
        'jam_toko_mulai' => 8,                       // toko dianggap buka mulai jam ini selama `jamOperasi`
        'jam_aktif_kendaraan' => 14,                 // dipakai menyebar hitungan kendaraan survei (per hari) ke per jam
        'jam_aktif_mulai' => 6,
        'festival_pengali_kendaraan' => 1.25,        // perkiraan lonjakan lalu lintas saat hari festival (hanya untuk angka survei)
        'baseline_hari' => 7,
        'ispu_sehat_maks' => 100,                    // ISPU ≤ 100 (Baik/Sedang) = sehat untuk berjalan kaki
        'suhu_nyaman_maks' => 33.0,                  // °C: di atas ini disarankan menghindari jam tersebut
        // Pohon: kg CO2/pohon/tahun (Dahlan 2008/2010). Pohon lain memakai 'asumsi_rata2'.
        'daya_serap_pohon' => [
            'trembesi' => 28488, 'kenanga' => 756.59, 'beringin' => 535.9, 'mahoni' => 295.73, 'bungur' => 160.14,
            'flamboyan' => 42.2, 'tanjung' => 34.29, 'angsana' => 11.12, 'asumsi_rata2' => 100,
        ],
        // Sama dengan carbonBalance.js (JENIS_KENDARAAN): liter/100 km dan jenis BBM tiap kelas kendaraan.
        'liter_per_100km' => ['motor' => 2.66, 'mobil' => 11.79, 'bus' => 16.89, 'truk' => 15.15],
        'bbm_kendaraan' => ['motor' => 'bensin', 'mobil' => 'bensin', 'bus' => 'solar', 'truk' => 'solar'],
        // Plastik (ASUMSI — ganti saat ada kajian lokal)
        'solar_bench_kwh_hari' => 0.45,              // panel 100 W × ±4,5 jam sinar efektif (ASUMSI, ganti dengan data Solar Bench)
        'plastik' => ['kg_co2_per_kantong' => 0.04, 'kantong_per_transaksi' => 1, 'poin_per_transaksi' => 5, 'harga_per_kantong' => 300],
    ],

    // Check-in QR warga (flowchart: Scan QR lapak → Bawa tas sendiri? → Scan QR tujuan kedua → Kecepatan wajar?)
    'checkin' => [
        'maks_kecepatan_mps' => 4.5,   // lebih cepat = bukan jalan kaki → tidak dihitung
        'min_kecepatan_mps' => 0.25,   // terlalu lambat = bukan satu perjalanan
        'min_durasi_detik' => 20,
        'maks_durasi_detik' => 10800,  // 3 jam
        'min_jarak_m' => 30,
        'kedaluwarsa_menit' => 180,    // check-in awal yang tak diselesaikan dianggap gugur
    ],

    // Nilai ekonomi hijau (ASUMSI harga — ubah di sini, tampil jelas di halaman Cara Kami Menghitung)
    'ekonomi' => [
        'harga_karbon_per_ton' => 75000,   // Rp/tCO2e (kisaran harga bursa karbon IDXCarbon)
        'tarif_listrik_kwh' => 1444.7,     // Rp/kWh
        'harga_bbm_liter' => 10000,
        'harga_sampah_per_kg' => ['organik' => 500, 'anorganik' => 2000, 'pangan' => 0],
        'harga_bibit' => 15000,            // Rp per bibit pohon
    ],

    // Hadiah penukaran poin (flowchart: Poin dan leaderboard → Tukar voucher lapak atau bibit)
    'hadiah' => [
        'voucher_lapak' => ['nama' => 'Voucher lapak UMKM Rp10.000', 'poin' => 100],
        'bibit' => ['nama' => 'Bibit pohon (ditanam di koridor)', 'poin' => 150],
    ],

    // Pemetaan SDGs untuk Kartu Dampak.
    'sdgs' => [
        3 => 'Kehidupan sehat dan sejahtera',
        7 => 'Energi bersih dan terjangkau',
        11 => 'Kota dan permukiman berkelanjutan',
        12 => 'Konsumsi dan produksi bertanggung jawab',
        13 => 'Penanganan perubahan iklim',
    ],
];
