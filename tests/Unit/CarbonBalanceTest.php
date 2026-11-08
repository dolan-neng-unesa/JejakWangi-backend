<?php

namespace Tests\Unit;

use App\Models\KoridorModel;
use App\Services\Engine\CarbonBalance;
use Tests\TestCase;

/** Rumus server harus sama dengan jejak-wangi-app/src/lib/carbonBalance.js (termasuk bus & truk berbahan solar). */
class CarbonBalanceTest extends TestCase
{
    public function test_emisi_kendaraan_mencakup_bus_dan_truk_solar(): void
    {
        $k = new KoridorModel(['distance_meter' => 510, 'carbon_inputs' => [
            'pohon' => [['jenis' => 'angsana', 'jumlah' => 15], ['jenis' => 'mahoni', 'jumlah' => 5]],
            'kendaraanPerHari' => ['motor' => 4200, 'mobil' => 900, 'bus' => 40, 'truk' => 25],
            'toko' => [['unitAC' => 3, 'kWPerUnit' => 0.9, 'jamOperasi' => 16, 'faktorBeban' => 0.8]],
            'panjangKm' => 0.51,
        ]]);
        $n = CarbonBalance::hitung($k);

        $perKm = fn ($l, $f) => $l / 100 * $f;
        $harapan = 0.51 * (4200 * $perKm(2.66, 2.31) + 900 * $perKm(11.79, 2.31) + 40 * $perKm(16.89, 2.68) + 25 * $perKm(15.15, 2.68));
        $this->assertEqualsWithDelta($harapan, $n['emisiKendaraan'], 0.01);
        $this->assertGreaterThan(CarbonBalance::emisiKendaraan(['motor' => 4200, 'mobil' => 900], 0.51), $n['emisiKendaraan'], 'bus & truk harus menambah emisi');
        $this->assertEqualsWithDelta(3 * 0.9 * 16 * 0.8 * 0.87, $n['emisiAC'], 0.01);
    }
}
