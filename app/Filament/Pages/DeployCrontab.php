<?php

namespace App\Filament\Pages;

use App\Models\Schedule;
use App\Services\Crontab\CrontabDeployer;
use App\Services\Crontab\CrontabRenderer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Preview diff crontab lalu deploy atau rollback — satu-satunya jalan agar
 * perubahan di database benar-benar berlaku di server (§3.5 rencana).
 */
class DeployCrontab extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'Deploy crontab';

    protected static ?string $title = 'Deploy crontab';

    protected static ?int $navigationSort = 30;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected string $view = 'filament.pages.deploy-crontab';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function getTargetPath(): string
    {
        return app(CrontabDeployer::class)->targetPath();
    }

    /**
     * @return array<int, array{schedule: Schedule, problem: string}>
     */
    public function getProblems(): array
    {
        return app(CrontabRenderer::class)->validate();
    }

    /**
     * @return array<int, array{type: string, line: string}>
     */
    public function getDiff(): array
    {
        return app(CrontabDeployer::class)->diff();
    }

    public function getPreview(): string
    {
        return app(CrontabDeployer::class)->preview();
    }

    public function getEnabledCount(): int
    {
        return app(CrontabRenderer::class)->enabledSchedules()->count();
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, time: string}>
     */
    public function getBackups(): array
    {
        return array_map(fn (string $file) => [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file) ?: 0,
            'time' => date('d M Y H:i:s', filemtime($file) ?: 0),
        ], array_slice(app(CrontabDeployer::class)->backups(), 0, 10));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
            $this->rollbackAction(),
            $this->deployAction(),
        ];
    }

    public function deployAction(): Action
    {
        return Action::make('deploy')
            ->label('Deploy sekarang')
            ->icon('heroicon-o-rocket-launch')
            ->color('primary')
            ->authorize(fn () => auth()->user()->canManage())
            ->disabled(fn () => $this->getProblems() !== [])
            ->requiresConfirmation()
            ->modalHeading('Deploy ke '.$this->getTargetPath().'?')
            ->modalDescription(fn () => $this->getEnabledCount().' schedule aktif akan ditulis. File lama otomatis di-backup.')
            ->action(function () {
                try {
                    $result = app(CrontabDeployer::class)->apply();
                } catch (Throwable $e) {
                    Notification::make()->title('Deploy gagal')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Crontab ter-deploy')
                    ->body($result['path'].' ('.number_format($result['bytes']).' byte). Backup: '.($result['backup'] ?? '—'))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public function rollbackAction(): Action
    {
        return Action::make('rollback')
            ->label('Rollback ke backup terakhir')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize(fn () => auth()->user()->canManage())
            ->disabled(fn () => $this->getBackups() === [])
            ->requiresConfirmation()
            ->modalHeading('Kembalikan crontab ke versi sebelumnya?')
            ->modalDescription('Isi file saat ini akan di-backup lebih dulu, jadi langkah ini bisa dibatalkan lagi.')
            ->action(function () {
                try {
                    $result = app(CrontabDeployer::class)->rollback();
                } catch (Throwable $e) {
                    Notification::make()->title('Rollback gagal')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Crontab dikembalikan')
                    ->body('Dari backup: '.basename($result['restored_from']))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Muat ulang')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(fn () => null);
    }
}
