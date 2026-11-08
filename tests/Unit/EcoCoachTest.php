<?php

namespace Tests\Unit;

use App\Services\EcoCoach\EcoCoach;
use App\Services\Ispu;
use PHPUnit\Framework\TestCase;

/** Port uji.mjs (modul Node eco-coach) + pengaman angka. Murni unit: tanpa DB, tanpa jaringan. */
class EcoCoachTest extends TestCase
{
    private function data(): array
    {
        return [
            'pengguna' => [
                'jarakJalanM' => 450, 'co2TerhindarG' => 120, 'bensinHematL' => 0.05, 'rupiahHemat' => 500,
                'mingguIni' => ['kunjungan' => 3, 'jarakJalanM' => 1200, 'co2TerhindarG' => 340, 'kemasanSekaliPakaiDihindari' => 5],
            ],
            'koridor' => ['nama' => 'Koridor Pasar Blambangan', 'ispu' => 45, 'kategoriIspu' => 'Baik', 'polutanDominan' => 'PM2.5', 'suhuC' => 29.5, 'skorTeduh' => 64],
            'kawasan' => ['pejalanAktif' => 312, 'jarakJalanKm' => 85.4, 'co2TerhindarKg' => 85, 'kemasanSekaliPakaiDihindari' => 1240],
        ];
    }

    private function openai(?string $teks, int $status = 200, ?array &$dikirim = null): EcoCoach
    {
        $http = function (string $url, array $headers, array $body, float $timeout) use ($teks, $status, &$dikirim) {
            $dikirim = compact('url', 'headers', 'body', 'timeout');

            return ['status' => $status, 'json' => ['choices' => [['message' => ['content' => $teks]]]]];
        };

        return new EcoCoach(['provider' => 'openai', 'openai_api_key' => 'x', 'base_url' => 'http://palsu/v1/', 'model' => 'm', 'timeout_ms' => 5000], $http);
    }

    public function test_tanpa_provider_memakai_template_cadangan(): void
    {
        $h = (new EcoCoach(['provider' => null]))->jawab('ringkasan', $this->data());
        $this->assertSame('template', $h['sumber']);
        $this->assertStringContainsString('450 m', $h['teks']);
        $this->assertStringContainsString('120 g CO₂', $h['teks']);
        $this->assertStringContainsString('Rp500', $h['teks']);
        $this->assertStringContainsString('5 kemasan sekali pakai', $h['teks']);
    }

    public function test_jawaban_ai_dengan_angka_dari_data_lolos(): void
    {
        $h = $this->openai('Kamu berjalan 450 m dan menghemat 120 g CO₂, sekitar Rp500. ISPU koridor 45 (PM2.5), suhu 29,5°C. Datang lagi pukul 05.30 ya.')->jawab('ringkasan', $this->data());
        $this->assertSame('ai', $h['sumber']);
    }

    public function test_jawaban_ai_dengan_angka_karangan_ditolak(): void
    {
        $h = $this->openai('Kamu menghemat 2,7 kg CO₂ hari ini, setara menanam 14 pohon!')->jawab('ringkasan', $this->data());
        $this->assertSame('template', $h['sumber']);
        $this->assertStringContainsString('2.7', $h['alasan']);
        $this->assertStringContainsString('14', $h['alasan']);
    }

    public function test_format_ribuan_indonesia_dikenali(): void
    {
        $k = EcoCoach::susunKonteks($this->data());
        $this->assertTrue(EcoCoach::cekAngka('Bulan ini 1.240 kemasan sekali pakai tidak terpakai.', $k)['lolos']);
        $this->assertFalse(EcoCoach::cekAngka('Bulan ini 1.250 kemasan sekali pakai tidak terpakai.', $k)['lolos']);
    }

    public function test_nama_polutan_dan_rumus_kimia_tidak_dianggap_angka_karangan(): void
    {
        $k = EcoCoach::susunKonteks($this->data());
        $this->assertTrue(EcoCoach::cekAngka('PM2.5 dan PM10, CO2, SO2, NO2, O3 dijelaskan tanpa angka lain.', $k)['lolos']);
    }

    public function test_jam_tidak_dicek_tetapi_angka_lain_tetap_dicek(): void
    {
        $k = EcoCoach::susunKonteks($this->data());
        $this->assertTrue(EcoCoach::cekAngka('Datang pukul 05.30–06.30 atau 17:45.', $k)['lolos']);
        $this->assertFalse(EcoCoach::cekAngka('Suhu 35°C.', $k)['lolos']);
    }

    public function test_llm_error_memakai_template_dan_tidak_melempar(): void
    {
        $h = $this->openai(null, 500)->jawab('tanya', $this->data(), 'Udara sekarang bagaimana?');
        $this->assertSame('template', $h['sumber']);
        $this->assertStringContainsString('ISPU koridor saat ini 45 (Baik)', $h['teks']);
        $this->assertStringContainsString('suhu 29,5°C', $h['teks']);
    }

    public function test_jawaban_kosong_memakai_template(): void
    {
        $this->assertSame('template', $this->openai('')->jawab('tanya', $this->data(), 'halo')['sumber']);
    }

    public function test_mode_laporan_template_hanya_memuat_data_yang_ada(): void
    {
        $h = (new EcoCoach(['provider' => null]))->jawab('laporan', [
            'mingguan' => ['aktivitas' => 40, 'pejalanAktif' => 12, 'jarakJalanKm' => 31.5, 'co2TerhindarKg' => 7.2, 'jamTerpadatPejalan' => '06.00–07.00',
                'kemasanSekaliPakaiDihindari' => 410, 'perubahanKemasanPersen' => 18, 'arahPerubahanKemasan' => 'naik'],
        ]);
        $this->assertStringContainsString('40 oleh 12 pejalan', $h['teks']);
        $this->assertStringContainsString('31,5 km', $h['teks']);
        $this->assertStringContainsString('06.00–07.00', $h['teks']);
        $this->assertStringContainsString('naik 18% dari minggu lalu', $h['teks']);
        $this->assertStringNotContainsString('undefined', $h['teks']);
    }

    public function test_template_tidak_menulis_nilai_kosong_saat_sensor_tidak_ada(): void
    {
        $h = (new EcoCoach(['provider' => null]))->jawab('tanya', ['koridor' => ['nama' => 'Koridor A', 'skorTeduh' => 80]], 'Udara?');
        $this->assertSame('Maaf, Eco-Coach belum punya data untuk menjawab itu.', $h['teks']);
        $h2 = (new EcoCoach(['provider' => null]))->jawab('tanya', ['koridor' => ['nama' => 'Koridor A', 'suhuC' => 31.2]], 'Suhu?');
        $this->assertSame('Suhu 31,2°C di Koridor A.', $h2['teks']);
    }

    public function test_konteks_tidak_memuat_identitas_pribadi(): void
    {
        $d = $this->data();
        $d['pengguna'] += ['nama' => 'Budi', 'email' => 'budi@x.id', 'id' => 7];
        $json = json_encode(EcoCoach::susunKonteks($d));
        $this->assertStringNotContainsString('Budi', $json);
        $this->assertStringNotContainsString('budi@x.id', $json);
    }

    public function test_pesan_ke_llm_memuat_aturan_data_dan_pertanyaan_dipotong(): void
    {
        $dikirim = null;
        $this->openai('ok 450 m', 200, $dikirim)->jawab('tanya', $this->data(), str_repeat('a', 500));
        $this->assertSame('http://palsu/v1/chat/completions', $dikirim['url']);
        $this->assertSame('Bearer x', $dikirim['headers']['Authorization']);
        $sistem = $dikirim['body']['messages'][0]['content'];
        $user = $dikirim['body']['messages'][1]['content'];
        $this->assertStringContainsString('ISPU koridor', $sistem);
        $this->assertStringContainsString('"jarakJalanM": 450', $user);
        $this->assertStringContainsString(str_repeat('a', 300), $user);
        $this->assertStringNotContainsString(str_repeat('a', 301), $user);
    }

    public function test_anthropic_membaca_blok_konten_dan_mengirim_header_kunci(): void
    {
        $dikirim = null;
        $http = function ($url, $headers, $body, $t) use (&$dikirim) {
            $dikirim = compact('url', 'headers', 'body');

            return ['status' => 200, 'json' => ['content' => [['type' => 'text', 'text' => 'Kamu berjalan '], ['type' => 'text', 'text' => '450 m.']]]];
        };
        $h = (new EcoCoach(['provider' => 'anthropic', 'anthropic_api_key' => 'kunci', 'model' => null, 'timeout_ms' => 1000], $http))->jawab('ringkasan', $this->data());
        $this->assertSame('ai', $h['sumber']);
        $this->assertSame('Kamu berjalan 450 m.', $h['teks']);
        $this->assertSame('https://api.anthropic.com/v1/messages', $dikirim['url']);
        $this->assertSame('kunci', $dikirim['headers']['x-api-key']);
        $this->assertSame('claude-haiku-5-5', $dikirim['body']['model']);
        $this->assertSame(400, $dikirim['body']['max_tokens']);
    }

    public function test_laporan_memberi_ruang_token_lebih_besar(): void
    {
        $dikirim = null;
        $this->openai('x', 200, $dikirim)->jawab('laporan', ['mingguan' => ['aktivitas' => 1]]);
        $this->assertSame(700, $dikirim['body']['max_tokens']);
    }

    public function test_api_key_kosong_atau_provider_belum_diatur_jatuh_ke_template(): void
    {
        $a = (new EcoCoach(['provider' => 'anthropic', 'anthropic_api_key' => null]))->jawab('ringkasan', $this->data());
        $this->assertSame('template', $a['sumber']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY kosong', $a['alasan']);
        $b = (new EcoCoach(['provider' => null]))->jawab('ringkasan', $this->data());
        $this->assertStringContainsString('LLM_PROVIDER belum diatur', $b['alasan']);
    }

    public function test_mode_tidak_dikenal_jatuh_ke_template(): void
    {
        $this->assertSame('template', $this->openai('x')->jawab('ngawur', $this->data())['sumber']);
    }

    public function test_ispu_sama_dengan_rumus_di_aplikasi(): void
    {
        $this->assertSame(39, Ispu::subIndeks('pm25', 12.0));    // 12/15.5 * 50
        $this->assertSame(78, Ispu::subIndeks('pm25', 38.0));    // 50 + (38-15.5)/(55.4-15.5) * 50
        $this->assertSame(500, Ispu::subIndeks('pm10', 9999.0));
        $r = Ispu::hitung(['pm25' => 38, 'pm10' => 20, 'co' => 1000]);
        $this->assertSame(78, $r['value']);
        $this->assertSame('PM2.5', $r['dominan']);
        $this->assertSame('Sedang', $r['kategori']);
        $this->assertNull(Ispu::hitung([]));
        $this->assertSame('Tidak Sehat', Ispu::kategori(101));
    }
}
