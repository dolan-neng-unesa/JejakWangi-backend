<?php

namespace Database\Seeders;

use App\Models\CorridorReportModel;
use App\Models\KoridorModel;
use App\Models\UmkmModel;
use App\Models\WasteWeighingModel;
use Illuminate\Database\Seeder;

// Data CONTOH untuk halaman Lapor Koridor & Sampah/Pangan (ditandai "data contoh" di UI bila tabel kosong dipakai demo).
class ReportsWasteSeeder extends Seeder
{
    public function run(): void
    {
        $ids = KoridorModel::orderBy('sort_order')->orderBy('id')->pluck('id')->values();
        if ($ids->isEmpty() || CorridorReportModel::exists()) {
            return;
        }
        $cat = ['Sampah menumpuk' => 'sampah', 'Asap pembakaran terbuka' => 'udara', 'Saluran tersumbat' => 'drainase', 'Tempat sampah rusak' => 'fasilitas', 'Bau menyengat' => 'udara', 'Sampah di trotoar' => 'sampah'];
        $status = ['diproses', 'menunggu', 'selesai', 'diproses', 'menunggu', 'selesai'];
        $who = ['Tim Kebersihan', 'Admin DLH', 'Tim Drainase', 'Tim Kebersihan', 'Admin DLH', 'Tim Kebersihan'];
        $i = 0;
        foreach ($cat as $title => $c) {
            $t = now()->subDays(6 - $i)->setTime(8, 40);
            $r = CorridorReportModel::create([
                'koridor_id' => $ids[$i % $ids->count()], 'location' => 'Jl. Diponegoro, dekat halte', 'category' => $c, 'title' => $title,
                'description' => "$title di sekitar koridor. Mohon ditindaklanjuti.", 'status' => $status[$i],
                'assignee' => $status[$i] === 'menunggu' ? null : $who[$i], 'reporter_name' => 'Warga', 'history' => [],
                'created_at' => $t, 'updated_at' => $t,
            ]);
            $hist = [['title' => 'Laporan diterima', 'desc' => 'Warga menerima ID laporan.', 'at' => $t->toIso8601String()]];
            if ($status[$i] !== 'menunggu') {
                $hist[] = ['title' => 'Diverifikasi admin', 'desc' => 'Lokasi dan kategori sesuai; diteruskan ke DLH.', 'at' => $t->copy()->addMinutes(30)->toIso8601String()];
            }
            $r->update(['code' => sprintf('KRD-%04d', $r->id), 'history' => $hist]);
            $i++;
        }

        $lapak = UmkmModel::orderBy('id')->limit(2)->get();
        $rows = [
            ['BSS-001', 'Sritanjung', 'lapak', $lapak[0]->business_name ?? 'Lapak Bu Sri', $lapak[0]->id ?? null, [['sisa_sayur', 8], ['botol_plastik', 4]]],
            ['BSS-001', 'Sritanjung', 'lapak', $lapak[1]->business_name ?? 'Lapak Pak Agus', $lapak[1]->id ?? null, [['daun', 9], ['kertas', 6]]],
            ['BSS-002', 'Blambangan', 'lapak', $lapak[0]->business_name ?? 'Lapak Bu Sri', $lapak[0]->id ?? null, [['kaleng', 3], ['kaca', 5]]],
        ];
        $cfg = config('jejakwangi.sampah.jenis');
        foreach ($rows as $n => [$code, $stn, $type, $name, $umkm, $items]) {
            $calc = collect($items)->map(fn ($x) => ['jenis' => $x[0], 'label' => $cfg[$x[0]]['label'], 'grup' => $cfg[$x[0]]['grup'], 'kg' => $x[1], 'poin_per_kg' => $cfg[$x[0]]['poin_per_kg'], 'poin' => $x[1] * $cfg[$x[0]]['poin_per_kg']]);
            $w = WasteWeighingModel::create([
                'kind' => 'sampah', 'station_code' => $code, 'station_name' => $stn, 'depositor_type' => $type, 'depositor_name' => $name, 'umkm_id' => $umkm,
                'items' => $calc->all(), 'total_kg' => $calc->sum('kg'),
                'organik_kg' => $calc->where('grup', 'organik')->sum('kg'), 'anorganik_kg' => $calc->where('grup', 'anorganik')->sum('kg'),
                'points' => $calc->sum('poin'), 'status' => 'terkonfirmasi', 'weighed_at' => now()->subHours(3 + $n), 'confirmed_at' => now()->subHours(3 + $n),
                'processed_at' => $n === 0 ? now()->subHour() : null,
            ]);
            $w->update(['code' => sprintf('TMB-%05d', $w->id)]);
        }
    }
}
