<?php

namespace App\Console\Commands;

use App\Enums\FindingSeverity;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Client;
use App\Models\ImportFinding;
use App\Models\ImportRun;
use App\Models\Run;
use App\Models\Schedule;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Kesiapan cutover per client (§5 Fase 5 rencana).
 *
 * Migrasi dilakukan bergelombang, satu client sekaligus, dan tiap gelombang
 * punya syarat yang sama. Perintah ini mengumpulkan syarat itu menjadi satu
 * tabel supaya tidak perlu dicek manual di lima tempat berbeda.
 */
class CronCutoverStatusCommand extends Command
{
    protected $signature = 'cron:cutover-status
        {client? : Only this client code}
        {--ready : Only list clients that are ready to cut over}
        {--blocked : Only list clients that still have blockers}';

    protected $description = 'Report per-client readiness for cutting over from the legacy crontab';

    public function handle(): int
    {
        $clients = Client::query()
            ->when($this->argument('client'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($clients->isEmpty()) {
            $this->error('No client matched.');

            return self::FAILURE;
        }

        $findings = $this->openErrorsByClient();
        $rows = [];
        $readyCount = 0;

        foreach ($clients as $client) {
            $report = $this->inspect($client, $findings);

            if ($report['ready']) {
                $readyCount++;
            }

            if ($this->option('ready') && ! $report['ready']) {
                continue;
            }

            if ($this->option('blocked') && $report['ready']) {
                continue;
            }

            $rows[] = [
                $report['ready'] ? 'READY' : 'BLOCKED',
                $client->code,
                $client->is_active ? 'yes' : 'no',
                $report['enabled'].'/'.$report['total'],
                $report['errors'] ?: '—',
                $report['needs_review'] ? 'yes' : '—',
                $report['credentials'],
                $report['last_24h'],
            ];
        }

        $this->table(
            ['State', 'Client', 'Active', 'Enabled', 'Errors', 'Review', 'Credentials', 'Runs 24h (ok/fail)'],
            $rows,
        );

        $this->newLine();
        $this->line('Ready to cut over : '.$readyCount.' of '.$clients->count());

        if ($this->argument('client')) {
            $this->explain($clients->first(), $findings);
        } else {
            $this->comment('Run with a client code for the detailed blocker list, e.g. cron:cutover-status gn');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, int>  $findings
     * @return array<string, mixed>
     */
    private function inspect(Client $client, Collection $findings): array
    {
        $total = Schedule::where('client_id', $client->id)->count();
        $enabled = Schedule::where('client_id', $client->id)->where('is_enabled', true)->count();
        $errors = (int) ($findings[$client->code] ?? 0);

        $needsReview = $client->needs_review
            || Schedule::where('client_id', $client->id)->where('needs_review', true)->exists();

        $credentials = $this->credentialState($client);

        $recent = Run::query()
            ->where('client_id', $client->id)
            ->where('started_at', '>=', now()->subDay())
            ->whereNot('trigger', RunTrigger::DryRun->value)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $ok = (int) ($recent[RunStatus::Success->value] ?? 0);
        $bad = (int) ($recent[RunStatus::Failed->value] ?? 0) + (int) ($recent[RunStatus::Timeout->value] ?? 0);

        return [
            'total' => $total,
            'enabled' => $enabled,
            'errors' => $errors,
            'needs_review' => $needsReview,
            'credentials' => $credentials,
            'last_24h' => $ok.'/'.$bad,
            // Syarat minimum sebelum client boleh dinyalakan: tidak ada error
            // impor yang menganggur, kredensial lengkap, dan tidak ada penanda
            // review yang belum dijawab.
            'ready' => $errors === 0 && ! $needsReview && $credentials === 'ok' && $total > 0,
        ];
    }

    private function credentialState(Client $client): string
    {
        if ($client->auth_type->value === 'none') {
            return 'ok';
        }

        if (blank($client->auth_secret)) {
            return 'MISSING secret';
        }

        if ($client->auth_type->value === 'basic' && blank($client->auth_username)) {
            return 'MISSING user';
        }

        return 'ok';
    }

    /**
     * Error impor yang belum ditandai selesai, dihitung per kode client.
     *
     * Temuan menyimpan path file, bukan client_id, jadi pemetaannya lewat
     * segmen folder pertama — sama seperti cara importer membentuk client.
     *
     * @return Collection<string, int>
     */
    private function openErrorsByClient(): Collection
    {
        $latest = ImportRun::latest('id')->first();

        if ($latest === null) {
            return collect();
        }

        return ImportFinding::query()
            ->where('import_run_id', $latest->id)
            ->where('severity', FindingSeverity::Error->value)
            ->where('resolved', false)
            ->pluck('source_file')
            ->map(fn (?string $file) => $file ? explode('/', ltrim($file, '/'))[0] : null)
            ->filter()
            ->countBy();
    }

    private function explain(Client $client, Collection $findings): void
    {
        $report = $this->inspect($client, $findings);

        $this->newLine();
        $this->line('<options=bold>'.$client->code.' — '.$client->name.'</>');
        $this->line('  base URL : '.$client->base_url);
        $this->newLine();

        $blockers = [];

        if ($report['total'] === 0) {
            $blockers[] = 'No schedule exists for this client yet.';
        }

        if ($report['errors'] > 0) {
            $blockers[] = $report['errors'].' unresolved import error(s). See the reconciliation report.';
        }

        if ($report['needs_review']) {
            $blockers[] = 'The client or one of its schedules is still flagged for manual verification.';
        }

        if ($report['credentials'] !== 'ok') {
            $blockers[] = 'Credentials incomplete: '.$report['credentials'].'.';
        }

        if ($blockers === []) {
            $this->info('  No blockers. Follow the cutover steps in `docs/cutover.md` §7.');

            return;
        }

        $this->warn('  Blockers:');

        foreach ($blockers as $i => $blocker) {
            $this->line('   '.($i + 1).'. '.$blocker);
        }
    }
}
