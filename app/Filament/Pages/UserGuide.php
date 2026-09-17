<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class UserGuide extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'User guide';

    protected static ?string $title = 'User guide';

    protected static string|\UnitEnum|null $navigationGroup = 'Help';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'user-guide';

    protected string $view = 'filament.pages.user-guide';

    public string $document = 'overview';

    public function mount(): void
    {
        $requested = (string) request()->query('document', 'overview');
        $this->document = array_key_exists($requested, $this->getDocuments()) ? $requested : 'overview';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    /** @return array<string, array{label: string, group: string, path: string}> */
    public function getDocuments(): array
    {
        return [
            'overview' => ['label' => 'Overview & quick start', 'group' => 'User guide', 'path' => 'docs/user-guide.md'],
            'dashboard' => ['label' => 'Dashboard & insights', 'group' => 'User guide', 'path' => 'docs/user-guide/01-dashboard-dan-insights.md'],
            'clients' => ['label' => 'Clients', 'group' => 'User guide', 'path' => 'docs/user-guide/02-clients.md'],
            'templates' => ['label' => 'Task templates', 'group' => 'User guide', 'path' => 'docs/user-guide/03-task-templates.md'],
            'schedules' => ['label' => 'Schedules', 'group' => 'User guide', 'path' => 'docs/user-guide/04-schedules.md'],
            'runs' => ['label' => 'Execution logs', 'group' => 'User guide', 'path' => 'docs/user-guide/05-execution-logs.md'],
            'system' => ['label' => 'System & observability', 'group' => 'User guide', 'path' => 'docs/user-guide/06-system-dan-observability.md'],
            'daily' => ['label' => 'Daily operations', 'group' => 'User guide', 'path' => 'docs/user-guide/07-operasi-harian.md'],
            'docs-index' => ['label' => 'Documentation index', 'group' => 'Technical reference', 'path' => 'docs/README.md'],
            'technical' => ['label' => 'Technical artifact', 'group' => 'Technical reference', 'path' => 'docs/artifact-teknis-opsifin-scheduler.md'],
            'architecture' => ['label' => 'Architecture summary', 'group' => 'Technical reference', 'path' => 'docs/architecture.md'],
            'direct-operations' => ['label' => 'Direct HTTP operations', 'group' => 'Technical reference', 'path' => 'docs/direct-http-operations.md'],
            'validation' => ['label' => 'Direct HTTP validation', 'group' => 'Technical reference', 'path' => 'docs/direct-http-validation.md'],
            'runbook' => ['label' => 'Operations runbook', 'group' => 'Technical reference', 'path' => 'docs/operations.md'],
            'installation' => ['label' => 'Development installation', 'group' => 'Deployment & migration', 'path' => 'docs/installation.md'],
            'deployment' => ['label' => 'Production deployment', 'group' => 'Deployment & migration', 'path' => 'docs/deployment-vps.md'],
            'database-migration' => ['label' => 'Database migration', 'group' => 'Deployment & migration', 'path' => 'docs/database-migration-vps.md'],
            'direct-migration' => ['label' => 'Direct migration plan', 'group' => 'Deployment & migration', 'path' => 'docs/direct-bounded-http-migration-plan.md'],
            'redis-cutover' => ['label' => 'Redis/Horizon cutover', 'group' => 'Deployment & migration', 'path' => 'docs/redis-horizon-cutover-vps.md'],
            'queue-design' => ['label' => 'HTTP concurrency vs queue', 'group' => 'Design records', 'path' => 'docs/http-concurrency-vs-redis-queue.md'],
            'queue-reliability' => ['label' => 'Queue reliability rationale', 'group' => 'Design records', 'path' => 'docs/justifikasi-queue-redis-dan-reliability.md'],
            'handoff' => ['label' => 'Current handoff', 'group' => 'Design records', 'path' => 'docs/handoff.md'],
        ];
    }

    /** @return array{label: string, group: string, path: string} */
    public function getCurrentDocument(): array
    {
        return $this->getDocuments()[$this->document] ?? $this->getDocuments()['overview'];
    }

    public function getDocumentUrl(string $key): string
    {
        return static::getUrl(['document' => $key]);
    }

    public function getDocumentHtml(): Htmlable
    {
        $current = $this->getCurrentDocument();
        $path = base_path($current['path']);
        $markdown = file_get_contents($path);

        abort_if($markdown === false, 404);

        $markdown = $this->rewriteDocumentLinks($markdown, $path);

        return new HtmlString(Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    private function rewriteDocumentLinks(string $markdown, string $currentPath): string
    {
        $routesByPath = [];

        foreach ($this->getDocuments() as $key => $document) {
            $path = base_path($document['path']);
            $routesByPath[realpath($path) ?: $path] = $key;
        }

        return preg_replace_callback(
            '~\]\(([^)#]+\.md)(#[^)]*)?\)~',
            function (array $matches) use ($currentPath, $routesByPath): string {
                $target = realpath(dirname($currentPath).DIRECTORY_SEPARATOR.$matches[1]);

                if ($target === false || ! isset($routesByPath[$target])) {
                    return $matches[0];
                }

                return ']('.$this->getDocumentUrl($routesByPath[$target]).($matches[2] ?? '').')';
            },
            $markdown,
        ) ?? $markdown;
    }
}
