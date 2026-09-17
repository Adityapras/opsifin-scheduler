<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

$root = dirname(__DIR__, 2);
chdir($root);
$output = $argv[2] ?? sys_get_temp_dir();
$views = $output.'/opsifin-guide-views';
if (! is_dir($views)) {
    mkdir($views, 0775, true);
}
$env = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_CONFIG_CACHE' => $output.'/opsifin-guide-no-config.php',
    'APP_ROUTES_CACHE' => $output.'/opsifin-guide-no-routes.php',
    'APP_EVENTS_CACHE' => $output.'/opsifin-guide-no-events.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
    'VIEW_COMPILED_PATH' => $views,
];
foreach ($env as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('Fixture requires isolated in-memory SQLite.');
}
Artisan::call('migrate', ['--force' => true]);
$user = User::create([
    'name' => 'Guide reviewer', 'email' => 'guide@example.test', 'password' => 'fixture-only',
    'role' => 'admin', 'is_active' => true,
]);
Auth::guard('web')->setUser($user);
$request = Request::create('http://guide.test/admin/user-guide?document='.($argv[1] ?? 'overview'));
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
if ($response->getStatusCode() !== 200) {
    fwrite(STDERR, 'Fixture response: '.$response->getStatusCode()."\n");
    exit(1);
}
file_put_contents($output.'/opsifin-guide-'.($argv[1] ?? 'overview').'.html', $response->getContent());
echo 'Exported isolated page: '.($argv[1] ?? 'overview')."\n";
