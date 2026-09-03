<?php

declare(strict_types=1);

use App\Models\Allocation;
use App\Models\Node;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

// Wings expects these fields to be maps, not sequences. Yaml::dump can't tell an
// empty PHP array is meant to be a map, so force them to stdClass when empty -
// otherwise DUMP_EMPTY_ARRAY_AS_SEQUENCE below turns them into `[]` and wings
// fails to unmarshal the config on boot.
function forceEmptyArraysAsMaps(array $config): array
{
  if (($config['docker']['registries'] ?? null) === []) {
    $config['docker']['registries'] = new stdClass();
  }
  if (($config['docker']['overhead']['multipliers'] ?? null) === []) {
    $config['docker']['overhead']['multipliers'] = new stdClass();
  }
  if (($config['remote_query']['custom_headers'] ?? null) === []) {
    $config['remote_query']['custom_headers'] = new stdClass();
  }

  return $config;
}

require '/var/www/html/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function env_str(string $key, string $default = ''): string
{
  $value = getenv($key);

  return ($value === false || $value === '') ? $default : $value;
}

function env_bool(string $key, bool $default): bool
{
  $value = getenv($key);

  return ($value === false || $value === '') ? $default : !in_array(strtolower($value), ['false', '0', 'no', 'off'], true);
}

function info_line(string $message): void
{
  echo "[provision] $message\n";
}

// --- Admin user -------------------------------------------------------------

$adminEmail = env_str('ADMIN_EMAIL', 'admin@pelican.test');

if (User::query()->where('email', $adminEmail)->exists()) {
  info_line("admin user $adminEmail already exists");
} else {
  Artisan::call('p:user:make', [
    '--email' => $adminEmail,
    '--username' => env_str('ADMIN_USERNAME', 'admin'),
    '--password' => env_str('ADMIN_PASSWORD', 'password'),
    '--admin' => 1,
    '--no-interaction' => true,
  ]);
  info_line("created admin user $adminEmail");
}

// --- Node -------------------------------------------------------------------

$nodeName = env_str('NODE_NAME', 'local-wings');
$node = Node::query()->where('name', $nodeName)->first();

if ($node) {
  info_line("node '$nodeName' already exists");
} else {
  $node = Node::query()->create([
    'name' => $nodeName,
    'description' => 'Auto-provisioned Wings container for plugin development.',
    'public' => true,
    'fqdn' => env_str('NODE_FQDN', 'wings.pelican.test'),
    'scheme' => 'http',
    'behind_proxy' => false,
    'maintenance_mode' => false,
    // 0 = unlimited, -1 = unlimited overallocation.
    'memory' => 0,
    'memory_overallocate' => -1,
    'disk' => 0,
    'disk_overallocate' => -1,
    'cpu' => 0,
    'cpu_overallocate' => -1,
    'upload_size' => 1024,
    'daemon_listen' => 8080,
    'daemon_connect' => 8080,
    'daemon_sftp' => 2022,
    'daemon_sftp_alias' => env_str('ALLOCATION_ALIAS', '127.0.0.1'),
    'daemon_base' => '/var/lib/pelican/volumes',
  ]);
  info_line("created node '$nodeName'");
}

// --- Allocations ------------------------------------------------------------

$allocationIp = env_str('ALLOCATION_IP', '0.0.0.0');
$allocationAlias = env_str('ALLOCATION_ALIAS', '127.0.0.1');
$portStart = (int) env_str('ALLOCATION_PORT_START', '25565');
$portEnd = (int) env_str('ALLOCATION_PORT_END', '25580');
$created = 0;

for ($port = $portStart; $port <= $portEnd; $port++) {
  $allocation = Allocation::query()->firstOrCreate(
    ['node_id' => $node->id, 'ip' => $allocationIp, 'port' => $port],
    ['ip_alias' => $allocationAlias],
  );

  $created += $allocation->wasRecentlyCreated ? 1 : 0;
}

info_line("allocations $allocationIp:$portStart-$portEnd ready ($created new)");

// --- Wings configuration ----------------------------------------------------

$configPath = env_str('WINGS_CONFIG_PATH', '/wings-config/config.yml');
$generated = forceEmptyArraysAsMaps($node->getConfiguration());

// Wings has the Docker daemon bind these files into every game server container
// (/etc/passwd, /etc/group, /etc/machine-id). They default to /etc/pelican,
// which is a Windows bind mount here and therefore unresolvable for the daemon.
$generated['system']['user']['passwd']['directory'] = '/var/lib/pelican/etc';
$generated['system']['machine_id']['directory'] = '/var/lib/pelican/etc/machine-id';

if (is_file($configPath)) {
  // Wings expands this file on boot (docker network, uids, backup settings,
  // ...) and writes it back. Only patch the keys the panel owns so neither
  // those expansions nor any manual tweaks get clobbered.
  $existing = forceEmptyArraysAsMaps(Yaml::parseFile($configPath) ?: []);
  $merged = array_replace_recursive($existing, $generated);

  if ($merged === $existing) {
    info_line("wings config at $configPath is up to date");
  } else {
    file_put_contents($configPath, Yaml::dump($merged, 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP));
    info_line("patched wings config at $configPath - restart wings to apply");
  }
} else {
  file_put_contents($configPath, Yaml::dump($generated, 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP));
  info_line("wrote wings config to $configPath (remote: " . config('app.url') . ')');
}

touch('/tmp/provision-ready');
