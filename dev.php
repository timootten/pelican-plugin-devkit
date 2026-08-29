#!/usr/bin/env php
<?php
/**
 * Cross-platform helper for the local Pelican plugin dev stack.
 * Works identically on Windows, macOS and Linux (requires PHP CLI 8.1+).
 *
 * Run `php dev.php help` for the list of commands.
 */

declare(strict_types=1);

const HOSTS_MARKER = '# --- pelican-dev ---';

$root = __DIR__;
chdir($root);

function isWindows(): bool
{
  return PHP_OS_FAMILY === 'Windows';
}

function run(array $cmd): int
{
  $line = implode(' ', array_map('escapeshellarg', $cmd));
  passthru($line, $exit);
  return $exit;
}

function compose(array $args): int
{
  return run(array_merge(['docker', 'compose'], $args));
}

function loadEnv(string $root): array
{
  $values = [
    'PANEL_HOST' => 'panel.pelican.test',
    'WINGS_HOST' => 'wings.pelican.test',
    'PANEL_PORT' => '80',
    'WINGS_PORT' => '8080',
    'ADMIN_EMAIL' => 'admin@pelican.test',
    'ADMIN_PASSWORD' => 'password',
  ];

  $envFile = $root . DIRECTORY_SEPARATOR . '.env';
  if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
        $values[$m[1]] = trim($m[2], "\"'");
      }
    }
  }

  return $values;
}

function requiredHostnames(array $env): array
{
  return [$env['PANEL_HOST'], $env['WINGS_HOST']];
}

function hostsPath(): string
{
  if (isWindows()) {
    $sysRoot = getenv('SystemRoot') ?: 'C:\\Windows';
    return $sysRoot . '\\System32\\drivers\\etc\\hosts';
  }
  return '/etc/hosts';
}

function isElevated(): bool
{
  if (isWindows()) {
    exec('net session >nul 2>&1', $out, $code);
    return $code === 0;
  }
  if (function_exists('posix_getuid')) {
    return posix_getuid() === 0;
  }
  return trim((string) shell_exec('id -u')) === '0';
}

function flushDnsCache(): void
{
  if (isWindows()) {
    passthru('ipconfig /flushdns >NUL 2>&1');
  } elseif (PHP_OS_FAMILY === 'Darwin') {
    passthru('dscacheutil -flushcache 2>/dev/null; killall -HUP mDNSResponder 2>/dev/null');
  } else {
    // Best effort only - most Linux systems read /etc/hosts uncached.
    passthru('resolvectl flush-caches 2>/dev/null || true');
  }
}

function elevateAndRerun(string $root, array $args): void
{
  $script = $root . DIRECTORY_SEPARATOR . 'dev.php';

  if (isWindows()) {
    echo "Updating the hosts file needs administrator rights - requesting elevation...\n";
    $quoted = array_map(
      fn(string $a): string => "'" . str_replace("'", "''", $a) . "'",
      array_merge([$script], $args)
    );
    $argList = implode(',', $quoted);
    $phpBinary = str_replace("'", "''", PHP_BINARY);
    $psCommand = "Start-Process -FilePath '{$phpBinary}' -ArgumentList @({$argList}) -Verb RunAs -Wait";
    passthru('powershell -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg($psCommand));
    return;
  }

  echo "Updating the hosts file needs root rights - requesting elevation via sudo...\n";
  run(array_merge(['sudo', PHP_BINARY, $script], $args));
}

function stripMarkerBlock(array $lines): array
{
  $kept = [];
  $inBlock = false;
  foreach ($lines as $line) {
    if (trim($line) === HOSTS_MARKER) {
      $inBlock = !$inBlock;
      continue;
    }
    if (!$inBlock) {
      $kept[] = $line;
    }
  }
  return $kept;
}

function hostsOk(array $names): bool
{
  $lines = @file(hostsPath(), FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return false;
  }

  foreach ($names as $name) {
    $found = false;
    foreach ($lines as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || $trimmed[0] === '#') {
        continue;
      }
      if (preg_match('/(^|\s)' . preg_quote($name, '/') . '(\s|$)/', $line)) {
        $found = true;
        break;
      }
    }
    if (!$found) {
      return false;
    }
  }

  return true;
}

function setHosts(string $root, array $env): void
{
  $names = requiredHostnames($env);

  if (!isElevated()) {
    elevateAndRerun($root, ['hosts']);
    return;
  }

  $path = hostsPath();
  $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
  $kept = stripMarkerBlock($lines);

  $kept[] = HOSTS_MARKER;
  foreach ($names as $name) {
    $kept[] = "127.0.0.1\t{$name}";
  }
  $kept[] = HOSTS_MARKER;

  file_put_contents($path, implode("\n", $kept) . "\n");
  flushDnsCache();

  echo "Hosts file updated:\n";
  foreach ($names as $name) {
    echo "  127.0.0.1  {$name}\n";
  }
}

function removeHosts(string $root): void
{
  if (!isElevated()) {
    elevateAndRerun($root, ['unhosts']);
    return;
  }

  $path = hostsPath();
  $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
  $kept = stripMarkerBlock($lines);

  file_put_contents($path, implode("\n", $kept) . "\n");
  flushDnsCache();

  echo "Removed pelican-dev hosts entries.\n";
}

function showEndpoints(array $env): void
{
  $panelPort = $env['PANEL_PORT'] === '80' ? '' : ':' . $env['PANEL_PORT'];

  echo "\nPelican dev stack is up\n";
  echo "  Panel     http://{$env['PANEL_HOST']}{$panelPort}\n";
  echo "  Login     {$env['ADMIN_EMAIL']} / {$env['ADMIN_PASSWORD']}\n";
  echo "  Wings API http://{$env['WINGS_HOST']}:{$env['WINGS_PORT']}\n";
  echo "  Plugins   ./plugins  ->  /var/www/html/plugins\n\n";
}

function removeGameServerContainers(): void
{
  // Wings creates these outside the compose project, so `docker compose down`
  // never touches them - they'd otherwise keep running after a reset.
  exec('docker ps -aq --filter label=Service=Pelican', $ids);
  $ids = array_filter(array_map('trim', $ids));
  if ($ids === []) {
    return;
  }

  echo 'Removing ' . count($ids) . " game server container(s) ...\n";
  run(array_merge(['docker', 'rm', '-f'], $ids));
}

function removeDirectory(string $path): void
{
  if (!is_dir($path)) {
    return;
  }

  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
  }

  rmdir($path);
}

function syncPanelSource(string $root): int
{
  $target = $root . '/panel-src';
  $items = ['app', 'vendor', 'composer.json', 'composer.lock', 'resources/views'];

  echo "Syncing app/, vendor/ and views/ from the running panel container into ./panel-src (used for IDE autocomplete) ...\n";
  foreach ($items as $item) {
    $dest = $target . '/' . $item;
    if (is_dir($dest)) {
      removeDirectory($dest);
    } elseif (is_file($dest)) {
      unlink($dest);
    }
    @mkdir(dirname($dest), 0777, true);

    $code = compose(['cp', "panel:/var/www/html/{$item}", $dest]);
    if ($code !== 0) {
      fwrite(STDERR, "Could not copy {$item} for IDE autocomplete - continuing anyway.\n");
      return $code;
    }
  }

  echo "Done. ./panel-src now mirrors the panel's app/, vendor/ and views/ for IDE autocomplete.\n";
  return 0;
}

function printHelp(): void
{
  echo <<<'EOF'
Pelican plugin dev stack

  php dev.php up             Start everything and follow panel + wings logs
  php dev.php up -d          Same, but stay detached (no log follow)
  php dev.php down           Stop the stack, keep data
  php dev.php restart [svc]  Restart one or all services
  php dev.php status         Show container status
  php dev.php logs [svc...]  Follow logs (default: panel + wings)

  php dev.php artisan <...>  Run an artisan command in the panel container
  php dev.php shell          Open a shell in the panel container
  php dev.php refresh        Clear Filament/view/config caches after plugin edits
  php dev.php provision      Re-run provisioning (user, node, allocations, eggs, test server)
  php dev.php wings-config   Print the live Wings config.yml
  php dev.php wings-sync     Re-sync the Wings config and restart Wings
  php dev.php ide-sync       Refresh ./panel-src (IDE autocomplete) - "up" does this once already

  php dev.php hosts          Add *.pelican.test to the local hosts file
  php dev.php unhosts        Remove those hosts entries again
  php dev.php reset          Destroy all data and start over

EOF;
}

$command = strtolower($argv[1] ?? 'help');
$rest = array_slice($argv, 2);
$env = loadEnv($root);

switch ($command) {
  case 'up':
    $detached = in_array('-d', $rest, true) || in_array('--detach', $rest, true);
    if (!hostsOk(requiredHostnames($env))) {
      echo "Required hostnames are missing from the hosts file.\n";
      setHosts($root, $env);
    }
    $code = compose(['up', '-d', '--remove-orphans']);
    if ($code !== 0) {
      exit($code);
    }
    if (!is_dir($root . '/panel-src')) {
      syncPanelSource($root);
    }
    showEndpoints($env);
    if ($detached) {
      break;
    }
    // Containers already run detached above - this only attaches to their log output.
    echo "Following panel + wings logs (Ctrl+C stops watching, the stack keeps running) ...\n\n";
    exit(compose(['logs', '-f', '--tail', '150', 'panel', 'wings']));

  case 'down':
    exit(compose(['down', '--remove-orphans']));

  case 'restart':
    exit(compose(array_merge(['restart'], $rest)));

  case 'status':
    exit(compose(['ps']));

  case 'logs':
    $services = $rest !== [] ? $rest : ['panel', 'wings'];
    exit(compose(array_merge(['logs', '-f', '--tail', '150'], $services)));

  case 'artisan':
    exit(compose(array_merge(['exec', 'panel', 'php', 'artisan'], $rest)));

  case 'shell':
    exit(compose(['exec', 'panel', '/bin/ash']));

  case 'provision':
    exit(compose(['up', '--force-recreate', 'provision']));

  case 'refresh':
    // Drop every cache that can hide a plugin change, without a restart.
    foreach (['filament:optimize-clear', 'icons:clear', 'view:clear', 'config:clear', 'route:clear', 'event:clear', 'cache:clear'] as $target) {
      $code = compose(['exec', 'panel', 'php', 'artisan', $target]);
      if ($code !== 0) {
        exit($code);
      }
    }
    echo "Caches cleared.\n";
    break;

  case 'wings-config':
    $path = $root . '/data/wings/config.yml';
    if (!is_file($path)) {
      fwrite(STDERR, "data/wings/config.yml not found - run 'up' first.\n");
      exit(1);
    }
    readfile($path);
    break;

  case 'wings-sync':
    $code = compose(['up', '--force-recreate', 'provision']);
    if ($code !== 0) {
      exit($code);
    }
    exit(compose(['restart', 'wings']));

  case 'ide-sync':
    exit(syncPanelSource($root));

  case 'hosts':
    setHosts($root, $env);
    break;

  case 'unhosts':
    removeHosts($root);
    break;

  case 'reset':
    echo "This deletes the database, panel data and ALL game server files.\n";
    echo 'Type "reset" to confirm: ';
    $confirm = trim((string) fgets(STDIN));
    if ($confirm !== 'reset') {
      echo "Aborted.\n";
      break;
    }

    compose(['down', '-v', '--remove-orphans']);
    removeGameServerContainers();
    foreach (['panel', 'wings', 'logs'] as $dir) {
      removeDirectory($root . '/data/' . $dir);
    }
    // Game server volumes live inside the Docker VM, not in ./data.
    run(['docker', 'run', '--rm', '-v', '/var/lib/pelican:/volumes', 'alpine', 'sh', '-c', 'rm -rf /volumes/*']);
    echo "Reset done. Run 'php dev.php up' to rebuild the environment.\n";
    break;

  default:
    printHelp();
    break;
}
