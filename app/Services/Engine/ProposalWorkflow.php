<?php

namespace App\Services\Engine;

use App\Models\AdminNotificationModel;
use App\Models\FollowUpAction;
use App\Models\KoridorModel;
use App\Models\MitigationTarget;
use App\Models\Proposal;
use App\Models\Scenario;
use Illuminate\Support\Carbon;

/**
 * Diamond "Usulan disetujui?" pada flowchart.
 *   Ya    → jadwal penjemputan sampah / pemilahan mandiri (kategori sampah & pangan),
 *           atau skenario + baseline & target mitigasi (kategori koridor); lalu "Data baru" → Database per jam dihitung ulang.
 *   Tidak → Simulator skenario → "Perbaiki dampak" → usulan revisi kembali menunggu keputusan.
 */
class ProposalWorkflow
{
    public function __construct(private HourlyAggregator $agg)
    {
    }

    public function setuju(Proposal $p, ?int $userId, ?string $catatan = null): array
    {
        $tindakan = [];
        $p->forceFill(['status' => 'disetujui', 'catatan' => $catatan, 'decided_by' => $userId, 'decided_at' => now()])->save();

        $koridor = $p->koridor_id ? KoridorModel::find($p->koridor_id) : null;
        if (in_array($p->kategori, ['sampah', 'pangan'], true)) {
            $besok = Carbon::now(config('jejakwangi.timezone'))->addDay()->toDateString();
            $tindakan[] = FollowUpAction::create([
                'kind' => 'jadwal_penjemputan', 'judul' => 'Penjemputan sampah terpilah ke TPS3R', 'koridor_id' => $p->koridor_id,
                'station_code' => 'BSS-003', 'tanggal' => $besok, 'jam' => '06.00–08.00',
                'target_kg' => $p->dampak['sampah_kg'] ?? $p->dampak['pangan_kg'] ?? null, 'proposal_id' => $p->id,
            ]);
            if ($p->kategori === 'pangan') {
                $tindakan[] = FollowUpAction::create([
                    'kind' => 'pemilahan_mandiri', 'judul' => 'Pemilahan sampah mandiri lapak pasar', 'koridor_id' => $p->koridor_id,
                    'station_code' => 'BSS-003', 'tanggal' => $besok, 'target_kg' => $p->dampak['pangan_kg'] ?? null, 'proposal_id' => $p->id,
                    'catatan' => 'Pisahkan pangan layak, organik, dan anorganik di lapak sebelum ditimbang.',
                ]);
            }
        } elseif ($koridor) {
            // Usulan koridor: simpan skenario hasil simulasi + tetapkan baseline & target (sekali per koridor per usulan).
            if ($p->parameter) {
                $hasil = Simulator::jalankan($koridor, $p->parameter);
                $sk = Scenario::create(['nama' => 'Usulan #'.$p->id.' — '.$p->kategori, 'koridor_id' => $koridor->id, 'proposal_id' => $p->id, 'parameter' => $hasil['parameter'], 'hasil' => $hasil, 'created_by' => $userId]);
                $p->forceFill(['scenario_id' => $sk->id])->save();
                $tindakan[] = $sk;
                $persen = (float) max(5, min(50, $hasil['selisih']['persen_turun'] ?? 10));
                $tindakan[] = Baseline::tetapkan($koridor, round($persen, 1), now()->addMonths(3)->toDateString(), $userId, $p->id);
            } elseif ($p->kategori === 'udara') {
                $tindakan[] = FollowUpAction::create([
                    'kind' => 'pemilahan_mandiri', 'judul' => 'Tidak ada tindakan sampah — kirim saran waktu/rute ke warga', 'koridor_id' => $p->koridor_id,
                    'status' => 'berjalan', 'proposal_id' => $p->id, 'catatan' => 'Saran waktu/rute alternatif tampil otomatis saat warga scan QR parkir saat ISPU tidak sehat.',
                ]);
            }
        }

        // "Data baru" → kembali ke Database per jam.
        $this->agg->jalankan(now(), $p->koridor_id);
        AdminNotificationModel::notify('sistem', 'Usulan disetujui', $p->usulan);

        return $tindakan;
    }

    /** Tolak → jalankan Simulator dan hasilkan parameter perbaikan (belum membuat usulan baru). */
    public function tolak(Proposal $p, ?int $userId, ?string $catatan = null): ?array
    {
        $p->forceFill(['status' => 'ditolak', 'catatan' => $catatan, 'decided_by' => $userId, 'decided_at' => now()])->save();
        $koridor = $p->koridor_id ? KoridorModel::find($p->koridor_id) : null;
        if (! $koridor || ! $p->parameter) {
            return null;
        }
        $target = MitigationTarget::where('koridor_id', $koridor->id)->latest('id')->value('target_kg_hari');
        $perbaikan = Simulator::perbaiki($koridor, $p->parameter, $target !== null ? (float) $target : null);
        $sk = Scenario::create([
            'nama' => 'Perbaikan usulan #'.$p->id, 'koridor_id' => $koridor->id, 'proposal_id' => $p->id,
            'parameter' => $perbaikan['parameter'], 'hasil' => $perbaikan['hasil'], 'created_by' => $userId,
        ]);
        $p->forceFill(['scenario_id' => $sk->id])->save();

        return ['scenario' => $sk, 'parameter' => $perbaikan['parameter'], 'hasil' => $perbaikan['hasil'], 'catatan' => $perbaikan['catatan']];
    }

    /** "Perbaiki dampak": ajukan kembali usulan hasil simulasi sebagai usulan baru (menunggu). */
    public function ajukanRevisi(Proposal $lama): Proposal
    {
        $sk = $lama->scenario_id ? Scenario::find($lama->scenario_id) : null;
        $param = $sk?->parameter ?? $lama->parameter;
        $turun = $sk['hasil']['selisih']['emisi_bersih_turun_kg_hari'] ?? null;

        return Proposal::create([
            'ai_report_id' => $lama->ai_report_id, 'status' => 'menunggu', 'parent_id' => $lama->id,
            'dedupe_key' => null, 'kategori' => $lama->kategori, 'koridor_id' => $lama->koridor_id,
            'temuan' => $lama->temuan,
            'usulan' => 'Revisi: '.$lama->usulan.($turun !== null ? sprintf(' (diperbaiki, estimasi turun %s kg CO₂/hari).', $turun) : ''),
            'dampak' => $turun !== null ? ['co2_kg_per_hari' => $turun] : $lama->dampak,
            'parameter' => $param, 'scenario_id' => $sk?->id,
        ]);
    }
}
