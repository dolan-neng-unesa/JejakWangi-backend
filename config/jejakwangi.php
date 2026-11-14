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
];
