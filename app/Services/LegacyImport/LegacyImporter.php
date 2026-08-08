<?php

namespace App\Services\LegacyImport;

use App\Enums\FindingSeverity;
use App\Enums\LegacyPattern;
use App\Models\Client;
use App\Models\ClientTaskOverride;
use App\Models\ImportFinding;
use App\Models\ImportRun;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\LegacyImport\Dto\ParsedCronEntry;
use App\Services\LegacyImport\Dto\ParsedCurl;
use Cron\CronExpression;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mengubah repo cron legacy (crontab + configs/*.conf + 478 file .sh) menjadi
 * baris-baris di clients / task_templates / client_task_overrides / schedules.
 *
 * Prinsip:
 *  - Tidak pernah menebak diam-diam. Setiap ketidakcocokan menjadi ImportFinding
 *    supaya bisa direview manual sebelum cutover (lihat §7 rencana).
 *  - Data legacy disimpan apa adanya pada kolom legacy_* agar bisa dibandingkan
 *    ulang saat shadow run.
 */
class LegacyImporter
{
    /** Folder di dalam source path yang bukan folder client. */
    private const NON_CLIENT_DIRS = ['configs', 'jobs', 'logs', 'etc', '.vscode', '.git'];

    private string $sourcePath;

    /** @var array<string, string> */
    private array $envVars = [];

    /** @var array<string, array{file: string, vars: array<string, string>}> */
    private array $confs = [];

    /** @var array<string, string> task type => path job relatif */
    private array $gatewayRoutes = [];

    /** @var array<string, ParsedCurl> path job relatif => curl */
    private array $jobCurls = [];

    /** @var array<string, array<string, ParsedCurl>> folder => [scriptBase => curl] */
    private array $scriptCurls = [];

    /** @var array<int, array<string, mixed>> */
    private array $findings = [];

    /** @var array<string, int|array<string, int>> */
    private array $stats = [];

    /** @var array<string, string> scriptBase (lowercase) => template key */
    private array $scriptToTemplate = [];

    /** @var array<string, string> gateway task type => template key */
    private array $gatewayToTemplate = [];

    /** @var array<string, TaskTemplate> */
    private array $templates = [];

    /** @var array<string, Client> folder name => client */
    private array $clientsByFolder = [];

    /** @var array<string, Client> conf code => client */
    private array $clientsByConf = [];

    public function __construct(
        private readonly CrontabParser $crontabParser,
        private readonly ShellConfigParser $configParser,
        private readonly GatewayParser $gatewayParser,
        private readonly CurlParser $curlParser,
    ) {}

    public function import(string $sourcePath, bool $dryRun = false, ?int $userId = null): ImportRun
    {
        $this->sourcePath = rtrim($sourcePath, '/');

        if (! is_dir($this->sourcePath)) {
            throw new RuntimeException("Source path not found: {$this->sourcePath}");
        }

        $importRun = new ImportRun([
            'source_path' => $this->sourcePath,
            'started_at' => now(),
            'dry_run' => $dryRun,
            'user_id' => $userId,
        ]);

        $this->readEnv();
        $this->readConfigs();
        $this->readGateway();
        $this->readClientScripts();

        $this->buildTemplates();
        $this->buildClients();
        $this->buildOverrides();
        $entries = $this->buildSchedules();

        $this->stats['crontab_entries'] = count($entries);
        $this->stats['clients'] = count($this->clientsByFolder) + count(array_diff_key($this->clientsByConf, $this->clientsByFolder));
        $this->stats['task_templates'] = count($this->templates);

        $importRun->finished_at = now();
        $importRun->stats = $this->stats;
        $importRun->save();

        foreach ($this->findings as $finding) {
            ImportFinding::create($finding + ['import_run_id' => $importRun->id]);
        }

        return $importRun->load('findings');
    }

    // ------------------------------------------------------------------
    // Tahap 1 — baca sumber
    // ------------------------------------------------------------------

    private function readEnv(): void
    {
        $path = $this->sourcePath.'/opsifin_env.sh';

        if (is_file($path)) {
            $this->envVars = $this->configParser->parse(file_get_contents($path) ?: '');
        }

        $this->stats['env_vars'] = count($this->envVars);
    }

    private function readConfigs(): void
    {
        foreach (glob($this->sourcePath.'/configs/*.conf') ?: [] as $file) {
            $code = pathinfo($file, PATHINFO_FILENAME);
            $this->confs[$code] = [
                'file' => 'configs/'.basename($file),
                'vars' => $this->configParser->parse(file_get_contents($file) ?: ''),
            ];
        }

        $this->stats['config_files'] = count($this->confs);
    }

    private function readGateway(): void
    {
        $gatewayFile = $this->sourcePath.'/gateway.sh';

        if (is_file($gatewayFile)) {
            $this->gatewayRoutes = $this->gatewayParser->parseRouting(file_get_contents($gatewayFile) ?: '');
        }

        // Variabel gateway di-substitusi dengan sentinel agar URL tetap bisa di-parse.
        $variables = [
            'API_URL' => 'https://__base__',
            'AUTH_TOKEN' => '__auth__',
            'API_SECRET_KEY' => '{{client.secret_key}}',
            'API_USERNAME' => '{{client.username}}',
            'API_PASSWORD' => '{{client.password}}',
            'CLIENT_NAME' => '',
        ];

        foreach (glob($this->sourcePath.'/jobs/*.sh') ?: [] as $file) {
            $relative = 'jobs/'.basename($file);
            $curl = $this->curlParser->parseFile(file_get_contents($file) ?: '', $variables);

            if ($curl === null) {
                $this->finding(FindingSeverity::Error, 'job_no_curl', "Job {$relative} contains no curl command.", $relative);

                continue;
            }

            $this->jobCurls[$relative] = $curl;
        }

        // BUG-1 — routing menunjuk file yang tidak ada.
        foreach ($this->gatewayRoutes as $task => $relative) {
            if (! isset($this->jobCurls[$relative])) {
                $this->finding(
                    FindingSeverity::Error,
                    'gateway_route_missing_file',
                    "gateway.sh routes task '{$task}' to {$relative}, but that file does not exist. ".
                    'The job fails silently (gateway still exits 0).',
                    'gateway.sh',
                    context: ['task' => $task, 'target' => $relative],
                );
            }
        }

        // BUG-2 — job ada tapi tidak pernah dirouting.
        foreach (array_keys($this->jobCurls) as $relative) {
            if (! in_array($relative, $this->gatewayRoutes, true)) {
                $this->finding(
                    FindingSeverity::Warning,
                    'job_not_routed',
                    "Job {$relative} exists in jobs/ but is not registered in the gateway.sh routing.",
                    $relative,
                );
            }
        }

        $this->stats['gateway_routes'] = count($this->gatewayRoutes);
        $this->stats['gateway_jobs'] = count($this->jobCurls);
    }

    private function readClientScripts(): void
    {
        $withoutMaxTime = 0;
        $total = 0;

        foreach (glob($this->sourcePath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $folder = basename($dir);

            if (in_array($folder, self::NON_CLIENT_DIRS, true)) {
                continue;
            }

            $scripts = glob($dir.'/*.sh') ?: [];

            if ($scripts === []) {
                continue;
            }

            foreach ($scripts as $file) {
                $base = pathinfo($file, PATHINFO_FILENAME);
                $relative = $folder.'/'.basename($file);
                $curl = $this->curlParser->parseFile(file_get_contents($file) ?: '', $this->envVars);
                $total++;

                if ($curl === null) {
                    $this->finding(FindingSeverity::Error, 'script_no_curl', "Script {$relative} contains no curl command.", $relative);

                    continue;
                }

                if ($curl->host === null) {
                    if ($curl->danglingUrl !== null) {
                        $this->finding(
                            FindingSeverity::Error,
                            'script_url_dangling',
                            "In {$relative} the URL is on a separate line without a backslash continuation, ".
                            "so curl runs with no URL at all and this job always fails. The intended URL: {$curl->danglingUrl}",
                            $relative,
                            context: ['dangling_url' => $curl->danglingUrl],
                        );
                    } else {
                        $this->finding(
                            FindingSeverity::Error,
                            'script_url_unresolved',
                            "The URL in {$relative} could not be resolved (an env variable is probably undefined): ".
                            ($curl->rawUrl ?? '(empty)'),
                            $relative,
                            context: ['raw_url' => $curl->rawUrl],
                        );
                    }

                    continue;
                }

                if ($curl->maxTime === null) {
                    $withoutMaxTime++;
                }

                $this->scriptCurls[$folder][$base] = $curl;
            }
        }

        $this->stats['client_folders'] = count($this->scriptCurls);
        $this->stats['client_scripts'] = $total;
        $this->stats['scripts_without_max_time'] = $withoutMaxTime;
    }

    // ------------------------------------------------------------------
    // Tahap 2 — task templates
    // ------------------------------------------------------------------

    private function buildTemplates(): void
    {
        $groups = $this->groupScriptsByName();
        $groups = $this->mergeGroupsWithSamePath($groups);

        $jobPathToKey = [];

        foreach ($this->jobCurls as $relative => $curl) {
            $gatewayKey = array_search($relative, $this->gatewayRoutes, true)
                ?: pathinfo($relative, PATHINFO_FILENAME);
            $jobPathToKey[$curl->path] = ['key' => $gatewayKey, 'file' => $relative, 'curl' => $curl];
        }

        $usedJobPaths = [];

        foreach ($groups as $group) {
            $canonicalPath = $group['path'];
            $job = $jobPathToKey[$canonicalPath] ?? null;

            if ($job !== null) {
                $usedJobPaths[$canonicalPath] = true;
                $key = $job['key'];
            } else {
                $key = Str::snake($group['names'][0]);
            }

            $template = $this->persistTemplate(
                key: $key,
                path: $canonicalPath,
                method: $group['method'],
                body: $group['body'],
                headers: $group['headers'],
                jobFile: $job['file'] ?? null,
                routed: $job !== null && in_array($job['file'], $this->gatewayRoutes, true),
                scriptNames: $group['names'],
            );

            foreach ($group['members'] as $scriptBase) {
                $this->scriptToTemplate[strtolower($scriptBase)] = $template->key;
            }

            if ($job !== null) {
                $gatewayTask = array_search($job['file'], $this->gatewayRoutes, true);

                if ($gatewayTask !== false) {
                    $this->gatewayToTemplate[$gatewayTask] = $template->key;
                }
            }
        }

        // Job gateway yang tidak punya padanan script client sama sekali.
        foreach ($jobPathToKey as $path => $job) {
            if (isset($usedJobPaths[$path])) {
                continue;
            }

            $template = $this->persistTemplate(
                key: $job['key'],
                path: $path,
                method: $job['curl']->method,
                body: $job['curl']->body,
                headers: $job['curl']->extraHeaders(),
                jobFile: $job['file'],
                routed: in_array($job['file'], $this->gatewayRoutes, true),
                scriptNames: [],
            );

            $gatewayTask = array_search($job['file'], $this->gatewayRoutes, true);

            if ($gatewayTask !== false) {
                $this->gatewayToTemplate[$gatewayTask] = $template->key;
            }
        }

        // Task yang dirouting gateway tapi file job-nya hilang (BUG-1): tetap dipetakan
        // ke template dengan nama file terdekat supaya schedule-nya tidak hilang.
        foreach ($this->gatewayRoutes as $task => $relative) {
            if (isset($this->gatewayToTemplate[$task])) {
                continue;
            }

            $guess = $this->guessTemplateForMissingJob($task);

            if ($guess !== null) {
                $this->gatewayToTemplate[$task] = $guess;
                $this->finding(
                    FindingSeverity::Warning,
                    'gateway_route_remapped',
                    "Gateway task '{$task}' was mapped to template '{$guess}' by name similarity, ".
                    "because {$relative} does not exist. Verify manually before enabling.",
                    'gateway.sh',
                    context: ['task' => $task, 'template' => $guess],
                );
            }
        }
    }

    /**
     * @return array<string, array{names: array<int,string>, members: array<int,string>, path: string, method: string, body: ?string, headers: array<string,string>, variants: array<string,int>}>
     */
    private function groupScriptsByName(): array
    {
        $raw = [];

        foreach ($this->scriptCurls as $folder => $scripts) {
            foreach ($scripts as $base => $curl) {
                $groupKey = strtolower($base);
                $raw[$groupKey]['name'] = $base;
                $raw[$groupKey]['paths'][$curl->path] = ($raw[$groupKey]['paths'][$curl->path] ?? 0) + 1;
                $raw[$groupKey]['methods'][$curl->method] = ($raw[$groupKey]['methods'][$curl->method] ?? 0) + 1;
                $bodyKey = $curl->body ?? "\0null";
                $raw[$groupKey]['bodies'][$bodyKey] = ($raw[$groupKey]['bodies'][$bodyKey] ?? 0) + 1;
                $headerKey = json_encode($curl->extraHeaders());
                $raw[$groupKey]['headers'][$headerKey] = ($raw[$groupKey]['headers'][$headerKey] ?? 0) + 1;
            }
        }

        $groups = [];

        foreach ($raw as $groupKey => $data) {
            arsort($data['paths']);
            arsort($data['methods']);
            arsort($data['bodies']);
            arsort($data['headers']);

            $body = array_key_first($data['bodies']);

            $groups[$groupKey] = [
                'names' => [$data['name']],
                'members' => [$groupKey],
                'path' => (string) array_key_first($data['paths']),
                'method' => (string) array_key_first($data['methods']),
                'body' => $body === "\0null" ? null : $body,
                'headers' => json_decode((string) array_key_first($data['headers']), true) ?: [],
                'variants' => $data['paths'],
            ];
        }

        return $groups;
    }

    /**
     * `billingFile.sh` dan `generateBillingFile.sh` menembak endpoint yang sama —
     * gabungkan supaya tidak lahir dua template untuk satu task.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function mergeGroupsWithSamePath(array $groups): array
    {
        $byPath = [];
        $merged = [];

        // Grup dengan anggota terbanyak menjadi tuan rumah.
        uasort($groups, fn ($a, $b) => array_sum($b['variants']) <=> array_sum($a['variants']));

        foreach ($groups as $groupKey => $group) {
            $path = $group['path'];

            if (isset($byPath[$path])) {
                $host = $byPath[$path];
                $merged[$host]['names'] = array_merge($merged[$host]['names'], $group['names']);
                $merged[$host]['members'] = array_merge($merged[$host]['members'], $group['members']);

                $this->finding(
                    FindingSeverity::Info,
                    'template_merged',
                    sprintf(
                        "Script '%s.sh' was merged into the same template as '%s.sh' because the endpoints are identical (%s).",
                        $group['names'][0],
                        $merged[$host]['names'][0],
                        $path,
                    ),
                    context: ['path' => $path, 'merged' => $group['names']],
                );

                continue;
            }

            $byPath[$path] = $groupKey;
            $merged[$groupKey] = $group;
        }

        return $merged;
    }

    private function guessTemplateForMissingJob(string $task): ?string
    {
        // update_status_auto_print_billing -> update_status_print_billing
        foreach ($this->templates as $key => $template) {
            if (str_replace('_', '', $key) === str_replace(['_', 'auto'], '', $task)) {
                return $key;
            }
        }

        foreach ($this->templates as $key => $template) {
            similar_text($key, $task, $percent);

            if ($percent >= 85) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $scriptNames
     */
    private function persistTemplate(
        string $key,
        string $path,
        string $method,
        ?string $body,
        array $headers,
        ?string $jobFile,
        bool $routed,
        array $scriptNames,
    ): TaskTemplate {
        $defaults = config('opsifin_cron.defaults');

        $template = TaskTemplate::updateOrCreate(
            ['key' => $key],
            [
                'name' => Str::headline($key),
                'http_method' => $method,
                'path_template' => $path,
                'body_template' => $body,
                'headers' => $this->normalizeTemplateHeaders($headers),
                'default_timeout_sec' => $defaults['timeout_sec'],
                'default_connect_timeout_sec' => $defaults['connect_timeout_sec'],
                'default_retries' => $defaults['retries'],
                'is_active' => true,
                'legacy_gateway_routed' => $routed,
                'legacy_job_file' => $jobFile,
                'legacy_script_names' => $scriptNames,
            ],
        );

        $this->templates[$key] = $template;

        return $template;
    }

    /**
     * Nilai SecretKey berbeda tiap client, jadi disimpan sebagai placeholder
     * dan diisi runner dari kredensial client.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function normalizeTemplateHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[$name] = strcasecmp($name, 'SecretKey') === 0
                ? '{{client.secret_key}}'
                : $value;
        }

        return $normalized;
    }

    // ------------------------------------------------------------------
    // Tahap 3 — clients
    // ------------------------------------------------------------------

    private function buildClients(): void
    {
        $sectionLabels = $this->sectionLabelsByClientKey();

        // 3a. Client dari folder script (Pola A).
        foreach ($this->scriptCurls as $folder => $scripts) {
            $hosts = [];
            $credentials = [];
            $secretKeys = [];

            $scriptsByCredential = [];

            foreach ($scripts as $base => $curl) {
                $hosts[$curl->baseUrl()] = ($hosts[$curl->baseUrl()] ?? 0) + 1;

                if ($curl->authUsername !== null) {
                    $credKey = $curl->authUsername."\0".$curl->authPassword;
                    $credentials[$credKey] = ($credentials[$credKey] ?? 0) + 1;
                    $scriptsByCredential[$credKey][] = $base.'.sh';
                }

                if ($curl->secretKey !== null && $curl->secretKey !== '') {
                    $secretKeys[$curl->secretKey] = ($secretKeys[$curl->secretKey] ?? 0) + 1;
                }
            }

            arsort($hosts);
            arsort($credentials);
            arsort($secretKeys);

            $baseUrl = (string) array_key_first($hosts);
            $username = null;
            $password = null;

            if ($credentials !== []) {
                [$username, $password] = explode("\0", (string) array_key_first($credentials), 2);
            }

            if (count($credentials) > 1) {
                $dominant = array_key_first($credentials);
                $outliers = [];

                foreach ($credentials as $credKey => $count) {
                    if ($credKey === $dominant) {
                        continue;
                    }

                    $outliers[] = sprintf(
                        "user '%s' in %s",
                        explode("\0", $credKey)[0],
                        implode(', ', $scriptsByCredential[$credKey] ?? []),
                    );
                }

                $this->finding(
                    FindingSeverity::Error,
                    'credential_drift',
                    sprintf(
                        "Folder %s/ uses %d different credentials. The client uses user '%s' (%d scripts). ".
                        'Deviating: %s. A client can only hold one credential, so the deviating scripts '.
                        'will run with the dominant credential after migration — confirm which one is correct.',
                        $folder,
                        count($credentials),
                        $username,
                        reset($credentials),
                        implode('; ', $outliers),
                    ),
                    $folder.'/',
                    context: [
                        'dominant_user' => $username,
                        'outliers' => $outliers,
                    ],
                );
            }

            $client = Client::updateOrCreate(
                ['code' => $folder],
                [
                    'name' => $sectionLabels[$folder] ?? Str::headline($folder),
                    'base_url' => $baseUrl,
                    'timezone' => config('opsifin_cron.default_timezone'),
                    'is_active' => true,
                    'auth_type' => $username !== null ? 'basic' : 'none',
                    'auth_username' => $username,
                    'auth_secret' => $password,
                    'auth_secret_key' => $secretKeys !== [] ? (string) array_key_first($secretKeys) : null,
                    'legacy_script_dir' => $folder.'/',
                    'needs_review' => count($credentials) > 1 || count($hosts) > 1,
                    'review_notes' => count($hosts) > 1
                        ? 'The folder uses more than one host: '.implode(', ', array_keys($hosts))
                        : null,
                ],
            );

            $this->clientsByFolder[$folder] = $client;
        }

        // 3b. Client dari configs/*.conf (Pola B), digabung bila host-nya sama.
        foreach ($this->confs as $code => $conf) {
            $vars = $conf['vars'];
            $apiUrl = rtrim($vars['API_URL'] ?? '', '/');

            if ($apiUrl === '') {
                $this->finding(FindingSeverity::Error, 'conf_no_url', "Config {$conf['file']} has no API_URL.", $conf['file']);

                continue;
            }

            $existing = $this->findFolderClientByBaseUrl($apiUrl);

            if ($existing !== null) {
                $this->reconcileConfWithFolderClient($existing, $code, $conf);
                $this->clientsByConf[$code] = $existing;

                continue;
            }

            $clashingFolder = $this->folderUsingHost($apiUrl);
            $clientCode = $code;

            if (isset($this->clientsByFolder[$code])) {
                // Nama sama tapi host beda — jangan digabung diam-diam.
                $clientCode = $code.'-gw';
                $this->finding(
                    FindingSeverity::Warning,
                    'base_url_conflict',
                    sprintf(
                        'Config %s uses %s while folder %s/ mostly points to %s. '.
                        "Created as a separate client with code '%s'. ".
                        'Confirm whether both are the same system (a domain migration) and merge them manually.',
                        $conf['file'],
                        $apiUrl,
                        $code,
                        $this->clientsByFolder[$code]->base_url,
                        $clientCode,
                    ),
                    $conf['file'],
                    context: ['conf_url' => $apiUrl, 'folder_url' => $this->clientsByFolder[$code]->base_url],
                );
            } elseif ($clashingFolder !== null) {
                $this->finding(
                    FindingSeverity::Info,
                    'conf_matches_secondary_host',
                    "Config {$conf['file']} ({$apiUrl}) uses a host that some scripts in folder {$clashingFolder}/ also use.",
                    $conf['file'],
                );
            }

            $client = Client::updateOrCreate(
                ['code' => $clientCode],
                [
                    'name' => $vars['CLIENT_NAME'] ?? Str::headline($code),
                    'base_url' => $apiUrl,
                    'timezone' => config('opsifin_cron.default_timezone'),
                    'is_active' => true,
                    'auth_type' => isset($vars['API_USERNAME']) ? 'basic' : 'none',
                    'auth_username' => $vars['API_USERNAME'] ?? null,
                    'auth_secret' => $vars['API_PASSWORD'] ?? null,
                    'auth_secret_key' => $vars['API_SECRET_KEY'] ?? null,
                    'legacy_config_file' => $conf['file'],
                    'needs_review' => $clientCode !== $code,
                    'review_notes' => $clientCode !== $code
                        ? "Possibly the same client as '{$code}' (script folder) but with a different base URL."
                        : null,
                ],
            );

            $this->clientsByConf[$code] = $client;
        }
    }

    private function findFolderClientByBaseUrl(string $baseUrl): ?Client
    {
        foreach ($this->clientsByFolder as $client) {
            if (rtrim($client->base_url, '/') === rtrim($baseUrl, '/')) {
                return $client;
            }
        }

        return null;
    }

    private function folderUsingHost(string $baseUrl): ?string
    {
        foreach ($this->scriptCurls as $folder => $scripts) {
            foreach ($scripts as $curl) {
                if (rtrim((string) $curl->baseUrl(), '/') === rtrim($baseUrl, '/')) {
                    return $folder;
                }
            }
        }

        return null;
    }

    /**
     * @param  array{file: string, vars: array<string, string>}  $conf
     */
    private function reconcileConfWithFolderClient(Client $client, string $confCode, array $conf): void
    {
        $vars = $conf['vars'];
        $confUser = $vars['API_USERNAME'] ?? null;
        $confPass = $vars['API_PASSWORD'] ?? null;

        $notes = [];

        if ($confUser !== null && $client->auth_username !== null && $confUser !== $client->auth_username) {
            // BUG-3 — kredensial berbeda antara script dan config.
            $this->finding(
                FindingSeverity::Error,
                'credential_drift',
                sprintf(
                    "Client %s: the scripts use user '%s' while %s uses '%s'. ".
                    'The importer keeps the credential from the scripts (used by most production jobs). '.
                    'Verify which one is valid with a connection test before enabling.',
                    $client->code,
                    $client->auth_username,
                    $conf['file'],
                    $confUser,
                ),
                $conf['file'],
                context: ['script_user' => $client->auth_username, 'conf_user' => $confUser],
            );

            $notes[] = "Credentials differ from {$conf['file']} (conf user: {$confUser}).";
        }

        $client->legacy_config_file = $conf['file'];

        if ($client->auth_username === null && $confUser !== null) {
            $client->auth_type = 'basic';
            $client->auth_username = $confUser;
            $client->auth_secret = $confPass;
        }

        if ($client->auth_secret_key === null && isset($vars['API_SECRET_KEY'])) {
            $client->auth_secret_key = $vars['API_SECRET_KEY'];
        }

        if (isset($vars['CLIENT_NAME']) && $vars['CLIENT_NAME'] !== '') {
            $client->name = $vars['CLIENT_NAME'];
        }

        if ($notes !== []) {
            $client->needs_review = true;
            $client->review_notes = trim(($client->review_notes ? $client->review_notes."\n" : '').implode("\n", $notes));
        }

        $client->save();
    }

    /**
     * Nama client yang enak dibaca diambil dari komentar seksi di crontab
     * (mis. "# -- Golden Nusa" tepat di atas blok job milik folder gn/).
     *
     * @return array<string, string>
     */
    private function sectionLabelsByClientKey(): array
    {
        $labels = [];

        foreach ($this->cronEntries() as $entry) {
            if ($entry->clientKey === null || $entry->sectionLabel === null) {
                continue;
            }

            $labels[$entry->clientKey] ??= $entry->sectionLabel;
        }

        return $labels;
    }

    // ------------------------------------------------------------------
    // Tahap 4 — override per client
    // ------------------------------------------------------------------

    private function buildOverrides(): void
    {
        $created = 0;
        /** @var array<string, array{script: string, values: array<string, mixed>}> */
        $seen = [];

        foreach ($this->scriptCurls as $folder => $scripts) {
            $client = $this->clientsByFolder[$folder] ?? null;

            if ($client === null) {
                continue;
            }

            foreach ($scripts as $base => $curl) {
                $templateKey = $this->scriptToTemplate[strtolower($base)] ?? null;

                if ($templateKey === null) {
                    $this->finding(
                        FindingSeverity::Error,
                        'script_without_template',
                        "Script {$folder}/{$base}.sh is not mapped to any template.",
                        $folder.'/'.$base.'.sh',
                    );

                    continue;
                }

                $template = $this->templates[$templateKey];
                $override = [];

                if ($curl->path !== $template->path_template) {
                    $override['path_override'] = $curl->path;
                }

                if ($curl->method !== $template->http_method->value) {
                    $override['method_override'] = $curl->method;
                }

                if ($curl->body !== $template->body_template) {
                    $override['body_override'] = $curl->body;
                }

                $extraHeaders = $this->normalizeTemplateHeaders($curl->extraHeaders());

                if ($extraHeaders !== ($template->headers ?? [])) {
                    $override['headers_override'] = $extraHeaders;
                }

                $baseUrl = (string) $curl->baseUrl();

                if (rtrim($baseUrl, '/') !== rtrim($client->base_url, '/')) {
                    $override['base_url_override'] = $baseUrl;
                    $this->reportHostMismatch($folder, $base, $client, $baseUrl);
                }

                $script = $folder.'/'.$base.'.sh';
                $pairKey = $client->id.':'.$template->id;

                // Satu client bisa punya dua script berbeda yang menembak endpoint sama
                // (mis. billingFile.sh dan generateBillingFile.sh). Model data hanya
                // menyimpan satu override per pasangan, jadi bentrokan harus dilaporkan.
                if (isset($seen[$pairKey])) {
                    $previous = $seen[$pairKey];

                    if ($previous['values'] !== $override) {
                        $winner = $this->pickOverrideWinner($folder, $previous['script'], $script);

                        $this->finding(
                            FindingSeverity::Error,
                            'override_collision',
                            sprintf(
                                "Client '%s' has two scripts for task '%s' with different configurations: %s and %s. ".
                                'The one used: %s (referenced by the crontab / found first). Confirm which is correct and delete the other.',
                                $client->code,
                                $template->key,
                                $previous['script'],
                                $script,
                                $winner,
                            ),
                            $script,
                            context: [
                                'task' => $template->key,
                                'candidates' => [$previous['script'], $script],
                                'winner' => $winner,
                            ],
                        );

                        if ($winner !== $script) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                $seen[$pairKey] = ['script' => $script, 'values' => $override];

                if ($override === []) {
                    // Pemenang bentrokan bisa saja justru tidak butuh override sama sekali;
                    // baris sisa dari script yang kalah harus dibuang.
                    $created -= ClientTaskOverride::where('client_id', $client->id)
                        ->where('task_template_id', $template->id)
                        ->delete();

                    continue;
                }

                ClientTaskOverride::updateOrCreate(
                    ['client_id' => $client->id, 'task_template_id' => $template->id],
                    $override + ['legacy_script_file' => $script],
                );

                $created++;
            }
        }

        $this->stats['overrides'] = $created;
    }

    /**
     * Bila dua script bentrok, menangkan yang benar-benar dipanggil crontab.
     */
    private function pickOverrideWinner(string $folder, string $previousScript, string $currentScript): string
    {
        $previousReferenced = $this->isReferencedInCrontab($folder, basename($previousScript, '.sh'));
        $currentReferenced = $this->isReferencedInCrontab($folder, basename($currentScript, '.sh'));

        if ($currentReferenced && ! $previousReferenced) {
            return $currentScript;
        }

        return $previousScript;
    }

    private function isReferencedInCrontab(string $folder, string $scriptBase): bool
    {
        foreach ($this->cronEntries() as $entry) {
            if ($entry->clientKey === $folder && $entry->taskKey === $scriptBase) {
                return true;
            }
        }

        return false;
    }

    private function reportHostMismatch(string $folder, string $base, Client $client, string $baseUrl): void
    {
        $owner = null;

        foreach ($this->clientsByFolder as $otherFolder => $other) {
            if ($otherFolder !== $folder && rtrim($other->base_url, '/') === rtrim($baseUrl, '/')) {
                $owner = $otherFolder;
                break;
            }
        }

        if ($owner !== null) {
            $this->finding(
                FindingSeverity::Error,
                'cross_client_host',
                "Script {$folder}/{$base}.sh targets {$baseUrl}, which is the host of client '{$owner}', ".
                "not the host of client '{$client->code}' ({$client->base_url}). Most likely a bad copy — confirm before migrating.",
                $folder.'/'.$base.'.sh',
                context: ['expected' => $client->base_url, 'actual' => $baseUrl, 'owner' => $owner],
            );

            return;
        }

        $this->finding(
            FindingSeverity::Warning,
            'host_mismatch',
            "Script {$folder}/{$base}.sh uses {$baseUrl}, which differs from the base URL of client '{$client->code}' ({$client->base_url}). ".
            'Stored as base_url_override.',
            $folder.'/'.$base.'.sh',
            context: ['expected' => $client->base_url, 'actual' => $baseUrl],
        );
    }

    // ------------------------------------------------------------------
    // Tahap 5 — schedules
    // ------------------------------------------------------------------

    /**
     * @return array<int, ParsedCronEntry>
     */
    private function buildSchedules(): array
    {
        $entries = $this->cronEntries();
        $created = 0;
        $active = 0;
        $commented = 0;
        $withFlock = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $entry->isCommented ? $commented++ : $active++;

            if ($entry->hasFlock && ! $entry->isCommented) {
                $withFlock++;
            }

            if ($entry->clientKey === null || $entry->taskKey === null) {
                $this->finding(
                    FindingSeverity::Info,
                    'not_a_job',
                    'The cron line points to neither a client script nor the gateway: '.Str::limit($entry->command, 120),
                    'opsifin_crontab',
                    $entry->lineNo,
                );
                $skipped++;

                continue;
            }

            [$client, $template] = $this->resolveEntryTarget($entry);

            if ($client === null || $template === null) {
                $skipped++;

                continue;
            }

            if (! CronExpression::isValidExpression($entry->cronExpression)) {
                $this->finding(
                    FindingSeverity::Error,
                    'invalid_cron_expression',
                    "The cron expression '{$entry->cronExpression}' is not valid.",
                    'opsifin_crontab',
                    $entry->lineNo,
                );
                $skipped++;

                continue;
            }

            $this->checkSuspiciousInterval($entry);

            $lockKey = $client->code.'.'.$template->key;

            $schedule = Schedule::firstOrNew([
                'client_id' => $client->id,
                'task_template_id' => $template->id,
                'cron_expression' => $entry->cronExpression,
            ]);

            if ($schedule->exists) {
                $this->finding(
                    FindingSeverity::Warning,
                    'duplicate_schedule',
                    sprintf(
                        "Line %d duplicates the existing schedule %s / %s / '%s' (line %s).",
                        $entry->lineNo,
                        $client->code,
                        $template->key,
                        $entry->cronExpression,
                        $schedule->legacy_line_no,
                    ),
                    'opsifin_crontab',
                    $entry->lineNo,
                );

                // Bila salah satu duplikat aktif, schedule dianggap aktif.
                if (! $entry->isCommented) {
                    $schedule->is_enabled = true;
                    $schedule->legacy_was_commented = false;
                    $schedule->save();
                }

                continue;
            }

            $schedule->fill([
                'timezone' => config('opsifin_cron.default_timezone'),
                'lock_key' => $lockKey,
                'lock_mode' => config('opsifin_cron.defaults.lock_mode'),
                'lock_wait_sec' => 0,
                'is_enabled' => ! $entry->isCommented,
                'catchup_policy' => 'skip',
                'legacy_pattern' => $entry->pattern,
                'legacy_line_no' => $entry->lineNo,
                'legacy_command' => $entry->command,
                'legacy_was_commented' => $entry->isCommented,
                'legacy_had_flock' => $entry->hasFlock,
                'legacy_lock_file' => $entry->lockFile,
                'needs_review' => $client->needs_review,
            ]);

            $schedule->recalculateNextRun();
            $schedule->save();
            $created++;
        }

        $this->stats['schedules'] = $created;
        $this->stats['entries_active'] = $active;
        $this->stats['entries_commented'] = $commented;
        $this->stats['entries_skipped'] = $skipped;
        $this->stats['legacy_active_with_flock'] = $withFlock;

        return $entries;
    }

    /**
     * @return array{0: ?Client, 1: ?TaskTemplate}
     */
    private function resolveEntryTarget(ParsedCronEntry $entry): array
    {
        if ($entry->pattern === LegacyPattern::Gateway) {
            $client = $this->clientsByConf[$entry->clientKey] ?? null;

            $severity = $entry->isCommented ? FindingSeverity::Warning : FindingSeverity::Error;
            $suffix = $entry->isCommented ? ' (the line is already commented out — a deletion candidate)' : '';

            if ($client === null) {
                $this->finding(
                    $severity,
                    'gateway_client_unknown',
                    "The line calls gateway.sh with client '{$entry->clientKey}', but configs/{$entry->clientKey}.conf does not exist.".$suffix,
                    'opsifin_crontab',
                    $entry->lineNo,
                );

                return [null, null];
            }

            $templateKey = $this->gatewayToTemplate[$entry->taskKey] ?? null;

            if ($templateKey === null) {
                $this->finding(
                    $severity,
                    'gateway_task_unknown',
                    "Gateway task '{$entry->taskKey}' is recognised by neither the gateway.sh routing nor jobs/.".$suffix,
                    'opsifin_crontab',
                    $entry->lineNo,
                );

                return [null, null];
            }

            return [$client, $this->templates[$templateKey]];
        }

        // Entry yang sudah di-comment tidak berjalan, jadi cukup diperlakukan
        // sebagai sisa mati yang perlu dibersihkan — bukan kegagalan impor.
        $severity = $entry->isCommented ? FindingSeverity::Warning : FindingSeverity::Error;
        $suffix = $entry->isCommented ? ' (the line is already commented out — a deletion candidate)' : '';

        $client = $this->clientsByFolder[$entry->clientKey] ?? null;

        if ($client === null) {
            $this->finding(
                $severity,
                'client_folder_missing',
                "The line calls a script in folder '{$entry->clientKey}/', but that folder does not exist in the source.".$suffix,
                'opsifin_crontab',
                $entry->lineNo,
            );

            return [null, null];
        }

        $templateKey = $this->scriptToTemplate[strtolower($entry->taskKey)] ?? null;

        if ($templateKey === null) {
            $this->finding(
                $severity,
                'script_missing',
                "The line calls {$entry->clientKey}/{$entry->taskKey}.sh, but that script does not exist (or failed to parse).".$suffix,
                'opsifin_crontab',
                $entry->lineNo,
            );

            return [null, null];
        }

        if (! isset($this->scriptCurls[$entry->clientKey][$entry->taskKey])) {
            $this->finding(
                FindingSeverity::Warning,
                'script_not_in_client_folder',
                "The line calls {$entry->clientKey}/{$entry->taskKey}.sh, but that file is not in the client folder ".
                '(the template is still taken from another client with the same script name).',
                'opsifin_crontab',
                $entry->lineNo,
            );
        }

        return [$client, $this->templates[$templateKey]];
    }

    /**
     * BUG-5 — `*​/50` bukan berarti "setiap 50 menit".
     */
    private function checkSuspiciousInterval(ParsedCronEntry $entry): void
    {
        $minute = explode(' ', $entry->cronExpression)[0] ?? '';

        if (! preg_match('#^\*/(\d+)$#', $minute, $m)) {
            return;
        }

        $step = (int) $m[1];

        if ($step > 0 && 60 % $step !== 0) {
            // Sisa jeda yang jauh lebih pendek dari step (mis. */59 → 59 lalu 1 menit)
            // hampir pasti salah tafsir; sisa yang mendekati step hanya kosmetik.
            $severity = (60 % $step) < $step / 2
                ? FindingSeverity::Warning
                : FindingSeverity::Info;

            $this->finding(
                $severity,
                'suspicious_interval',
                sprintf(
                    "Expression '%s' runs at minutes %s — the interval is uneven (%d minutes then %d minutes), ".
                    'which is probably not what was intended.',
                    $entry->cronExpression,
                    implode(', ', range(0, 59, $step)),
                    $step,
                    60 % $step,
                ),
                'opsifin_crontab',
                $entry->lineNo,
                context: ['expression' => $entry->cronExpression, 'step' => $step],
            );
        }
    }

    /** @var array<int, ParsedCronEntry>|null */
    private ?array $cronEntriesCache = null;

    /**
     * @return array<int, ParsedCronEntry>
     */
    private function cronEntries(): array
    {
        if ($this->cronEntriesCache !== null) {
            return $this->cronEntriesCache;
        }

        $file = $this->sourcePath.'/opsifin_crontab';

        if (! is_file($file)) {
            throw new RuntimeException("opsifin_crontab file not found in {$this->sourcePath}");
        }

        return $this->cronEntriesCache = $this->crontabParser->parse(file_get_contents($file) ?: '');
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function finding(
        FindingSeverity $severity,
        string $category,
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?array $context = null,
    ): void {
        $this->findings[] = [
            'severity' => $severity,
            'category' => $category,
            'message' => $message,
            'source_file' => $file,
            'source_line' => $line,
            'context' => $context,
        ];
    }
}
