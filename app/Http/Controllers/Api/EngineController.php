<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiReport;
use App\Models\FollowUpAction;
use App\Models\HourlyMetric;
use App\Models\KoridorModel;
use App\Models\MitigationTarget;
use App\Models\Proposal;
use App\Models\QrCheckin;
use App\Models\QrPoint;
use App\Models\Scenario;
use App\Models\SensorReadingModel;
use App\Models\WasteWeighingModel;
use App\Services\Engine\Baseline;
use App\Services\Engine\GreenEconomy;
use App\Services\Engine\HourlyAggregator;
use App\Services\Engine\Neraca;
use App\Services\Engine\OpenMeteo;
use App\Services\Engine\ProposalWorkflow;
use App\Services\Engine\ReportAssistant;
use App\Services\Engine\Simulator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/** Endpoint admin untuk lapisan "Server Lokal" dan "Tindak Lanjut" pada flowchart (semua di grup admin). */
class EngineController extends Controller
{
    private function periode(Request $r, int $default): array
    {
        $hari = max(1, min(90, (int) $r->query('days', $default)));

        return [Carbon::now()->subDays($hari), Carbon::now(), $hari];
    }

    private function invalid($v)
    {
        return response()->json(['message' => 'Data tidak valid', 'errors' => $v->errors()], 422);
    }

    /** Status 8 sumber data: kapan terakhir masuk dan asalnya (nyata/API/seder). */
    public function sumber()
    {
        $terakhir = fn (array $types, ?array $devices = null) => SensorReadingModel::whereIn('sensor_type', $types)
            ->when($devices, fn ($q) => $q->whereIn('device_code', $devices))->orderByDesc('recorded_at')->first();
        $fmt = function (string $kunci, string $nama, $row, ?int $n = null) {
            $asal = ! $row ? 'kosong' : (in_array($row->device_code, [OpenMeteo::DEVICE_SEED], true) || str_starts_with($row->device_code, 'seed') ? 'seder'
                : ($row->device_code === OpenMeteo::DEVICE ? 'api' : 'sensor'));

            return ['kunci' => $kunci, 'nama' => $nama, 'asal' => $asal, 'terakhir' => $row ? Carbon::parse($row->recorded_at)->toIso8601String() : null, 'jumlah' => $n];
        };
        $T = SensorReadingModel::class;
        $out = [
            $fmt('surya', 'Sensor energi Solar Bench', $terakhir([$T::TYPE_SOLAR_W]), SensorReadingModel::where('sensor_type', $T::TYPE_SOLAR_W)->count()),
            $fmt('yolo', 'Kamera YOLO koridor pasar', $terakhir([$T::TYPE_VEHICLE_MOTOR, $T::TYPE_VEHICLE_CAR]), SensorReadingModel::whereIn('sensor_type', [$T::TYPE_VEHICLE_MOTOR, $T::TYPE_VEHICLE_CAR])->count()),
            $fmt('open_meteo', 'API Open-Meteo (PM10, PM2.5, suhu)', $terakhir([$T::TYPE_PM25, $T::TYPE_PM10, $T::TYPE_TEMPERATURE_OPEN], [OpenMeteo::DEVICE, OpenMeteo::DEVICE_SEED]), SensorReadingModel::whereIn('device_code', [OpenMeteo::DEVICE, OpenMeteo::DEVICE_SEED])->count()),
            $fmt('suhu', 'Sensor suhu kanopi dan terbuka', $terakhir([$T::TYPE_TEMPERATURE, $T::TYPE_TEMPERATURE_OPEN]), SensorReadingModel::whereIn('sensor_type', [$T::TYPE_TEMPERATURE, $T::TYPE_TEMPERATURE_OPEN])->count()),
        ];
        $survei = KoridorModel::whereNotNull('carbon_inputs')->count();
        $out[] = ['kunci' => 'survei', 'nama' => 'Survei pohon dan AC toko', 'asal' => $survei ? 'survei' : 'kosong', 'terakhir' => null, 'jumlah' => $survei];
        $ci = QrCheckin::orderByDesc('started_at')->first();
        $out[] = ['kunci' => 'checkin', 'nama' => 'Check-in QR warga', 'asal' => $ci ? 'aplikasi' : 'kosong', 'terakhir' => $ci?->started_at?->toIso8601String(), 'jumlah' => QrCheckin::count()];
        $w = WasteWeighingModel::where('kind', 'pangan')->orderByDesc('weighed_at')->first();
        $out[] = ['kunci' => 'pangan', 'nama' => 'Timbang sisa pangan pasar', 'asal' => $w ? 'timbangan' : 'kosong', 'terakhir' => $w?->weighed_at?->toIso8601String(), 'jumlah' => WasteWeighingModel::where('kind', 'pangan')->count()];
        $fest = \App\Models\FestivalModel::active()->count();
        $out[] = ['kunci' => 'festival', 'nama' => 'Kalender Banyuwangi Festival', 'asal' => $fest ? 'kalender' : 'kosong', 'terakhir' => null, 'jumlah' => $fest];

        return response()->json(['data' => $out, 'baris_per_jam' => HourlyMetric::count(), 'jam_terakhir' => HourlyMetric::max('hour_at')]);
    }

    public function hourly(Request $r)
    {
        $jam = max(1, min(168, (int) $r->query('hours', 24)));
        $rows = HourlyMetric::when($r->query('koridor_id'), fn ($q, $id) => $q->where('koridor_id', $id))
            ->where('hour_at', '>=', now()->subHours($jam)->utc())->orderByDesc('hour_at')->limit(2000)->get();

        return response()->json(['data' => $rows->map(fn ($m) => $m->toArray())]);
    }

    /** Jalankan sekarang: tarik Open-Meteo lalu hitung ulang jam berjalan dan jam lalu. */
    public function recompute(OpenMeteo $meteo, HourlyAggregator $agg)
    {
        OpenMeteo::siapkanPerangkat();
        $api = $meteo->tarik();
        $n = $agg->jalankan(now()->subHour())->count() + $agg->jalankan(now())->count();

        return response()->json(['data' => ['open_meteo_baris' => $api, 'baris_dihitung' => $n]]);
    }

    /** Ringkasan neraca semua koridor + total kawasan (panel dashboard: Solar-Bench & Avoided-Emission). */
    public function neraca(Request $r)
    {
        [$dari, $sampai, $hari] = $this->periode($r, 7);
        $kor = KoridorModel::orderBy('sort_order')->orderBy('id')->get()->map(fn ($k) => ['koridor_id' => $k->id, 'koridor' => $k->short_name] + Neraca::ringkas($k->id, $dari, $sampai));

        return response()->json(['data' => ['periode_hari' => $hari, 'kawasan' => Neraca::ringkas(null, $dari, $sampai), 'koridor' => $kor]]);
    }

    public function rapor(Request $r)
    {
        return response()->json(['data' => Baseline::rapor(max(1, min(90, (int) $r->query('days', 7))))]);
    }

    public function ekonomi(Request $r)
    {
        [$dari, $sampai] = $this->periode($r, 30);

        return response()->json(['data' => GreenEconomy::hitung($r->query('koridor_id') ? (int) $r->query('koridor_id') : null, $dari, $sampai)]);
    }

    public function baselineIndex()
    {
        $rows = KoridorModel::orderBy('id')->get()->map(fn ($k) => Baseline::progres($k))->filter()->values();

        return response()->json(['data' => $rows]);
    }

    public function baselineStore(Request $request)
    {
        $v = Validator::make($request->all(), ['koridor_id' => ['required', 'integer', 'exists:koridors,id'], 'target_persen' => ['required', 'numeric', 'between:1,90'], 'tenggat' => ['nullable', 'date']]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $d = $v->validated();
        $t = Baseline::tetapkan(KoridorModel::findOrFail($d['koridor_id']), (float) $d['target_persen'], $d['tenggat'] ?? null, $request->user()->id);

        return response()->json(['data' => Baseline::progres(KoridorModel::find($d['koridor_id']), $t)], 201);
    }

    public function simulasi(Request $request)
    {
        $v = Validator::make($request->all(), [
            'koridor_id' => ['required', 'integer', 'exists:koridors,id'],
            'simpan' => ['nullable', 'boolean'], 'nama' => ['nullable', 'string', 'max:120'],
            'tambah_pohon' => ['nullable', 'array'], 'tambah_pohon.jenis' => ['nullable', 'string', 'max:40'], 'tambah_pohon.jumlah' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'persen_motor_jalan_kaki' => ['nullable', 'numeric', 'between:0,100'], 'persen_hemat_ac' => ['nullable', 'numeric', 'between:0,100'],
            'tambah_solar_bench' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $d = $v->validated();
        $k = KoridorModel::findOrFail($d['koridor_id']);
        $hasil = Simulator::jalankan($k, $d);
        if (! empty($d['simpan'])) {
            $s = Scenario::create(['nama' => $d['nama'] ?? 'Skenario '.now()->format('d/m H:i'), 'koridor_id' => $k->id, 'parameter' => $hasil['parameter'], 'hasil' => $hasil, 'created_by' => $request->user()->id]);
            $hasil['id'] = $s->id;
        }

        return response()->json(['data' => $hasil]);
    }

    public function skenario()
    {
        return response()->json(['data' => Scenario::orderByDesc('id')->limit(30)->get()]);
    }

    // ------------------------------------------------------------ Asisten Laporan & usulan

    public function laporanBaru(Request $request, ReportAssistant $asisten)
    {
        $r = $asisten->buat($request->user()->id);

        return response()->json(['data' => [
            'id' => $r['report']->id, 'teks' => $r['report']->teks, 'sumber' => $r['report']->sumber,
            'temuan' => $r['report']->temuan, 'usulan' => $r['usulan']->values(),
        ]], 201);
    }

    public function proposals(Request $r)
    {
        $rows = Proposal::when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByRaw("CASE status WHEN 'menunggu' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows, 'menunggu' => Proposal::where('status', 'menunggu')->count()]);
    }

    public function setuju(Request $request, int $id, ProposalWorkflow $wf)
    {
        $p = Proposal::findOrFail($id);
        if ($p->status !== 'menunggu') {
            return response()->json(['message' => 'Usulan ini sudah diputuskan.'], 422);
        }
        $t = $wf->setuju($p, $request->user()->id, $request->input('catatan'));

        return response()->json(['data' => $p->fresh(), 'tindakan' => $t]);
    }

    public function tolak(Request $request, int $id, ProposalWorkflow $wf)
    {
        $p = Proposal::findOrFail($id);
        if ($p->status !== 'menunggu') {
            return response()->json(['message' => 'Usulan ini sudah diputuskan.'], 422);
        }
        $perbaikan = $wf->tolak($p, $request->user()->id, $request->input('catatan'));

        return response()->json(['data' => $p->fresh(), 'perbaikan' => $perbaikan]);
    }

    public function revisi(int $id, ProposalWorkflow $wf)
    {
        $p = Proposal::findOrFail($id);
        if ($p->status !== 'ditolak') {
            return response()->json(['message' => 'Hanya usulan yang ditolak yang bisa direvisi.'], 422);
        }

        return response()->json(['data' => $wf->ajukanRevisi($p)], 201);
    }

    // ------------------------------------------------------------ pemilahan mandiri & jadwal penjemputan

    public function followUps(Request $r)
    {
        return response()->json(['data' => FollowUpAction::when($r->query('kind'), fn ($q, $k) => $q->where('kind', $k))->orderByRaw("CASE status WHEN 'selesai' THEN 1 WHEN 'batal' THEN 2 ELSE 0 END")->orderBy('tanggal')->orderByDesc('id')->limit(100)->get()]);
    }

    public function followUpStore(Request $request)
    {
        $v = Validator::make($request->all(), [
            'kind' => ['required', 'in:pemilahan_mandiri,jadwal_penjemputan'], 'judul' => ['required', 'string', 'max:160'],
            'station_code' => ['nullable', 'string', 'max:20'], 'tanggal' => ['nullable', 'date'], 'jam' => ['nullable', 'string', 'max:20'],
            'target_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'petugas' => ['nullable', 'string', 'max:120'], 'catatan' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }

        return response()->json(['data' => FollowUpAction::create($v->validated())], 201);
    }

    public function followUpUpdate(Request $request, int $id)
    {
        $v = Validator::make($request->all(), ['status' => ['required', 'in:terjadwal,berjalan,selesai,batal'], 'catatan' => ['nullable', 'string', 'max:1000']]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $a = FollowUpAction::findOrFail($id);
        $a->fill($v->validated())->save();
        // Penjemputan selesai = data baru → hitung ulang Database per jam.
        if ($a->status === 'selesai') {
            app(HourlyAggregator::class)->jalankan(now(), $a->koridor_id);
        }

        return response()->json(['data' => $a]);
    }

    // ------------------------------------------------------------ titik QR

    public function qrPoints()
    {
        return response()->json(['data' => QrPoint::orderBy('type')->orderBy('id')->get()->map(fn ($p) => $p->toArray() + ['qr_payload' => 'jejakwangi:titik:'.$p->code])]);
    }

    public function qrPointStore(Request $request)
    {
        $v = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:qr_points,code'], 'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:parkir,lapak,tujuan'], 'koridor_id' => ['nullable', 'integer', 'exists:koridors,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }

        return response()->json(['data' => QrPoint::create($v->validated())], 201);
    }
}
