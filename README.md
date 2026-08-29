# ShadeHost Pelican Plugin – Dev Environment

> **Unofficial.** Not affiliated with or endorsed by the Pelican project. This is a personal
> project by someone just getting started with Pelican plugin development - use at your own
> risk, and expect rough edges.

A complete local Pelican development environment (Panel + Wings + PostgreSQL + Redis) in Docker.
Everything is set up automatically on the first start: database, admin account, node,
allocations, eggs and the `config.yml` for Wings.

The `plugins/` folder is bind-mounted straight into the panel container, so plugin changes
take effect without a rebuild.

---

## Requirements

- **Docker Desktop** (WSL2 backend on Windows, Linux containers)
- **PHP CLI 8.1+** on the host (runs `dev.php` - works on Windows, macOS and Linux)
- Free ports: `80`, `8080`, `2022`, `5432`, `6379`

---

## Setup

```sh
git clone <repo> ShadeHost-Pelican-Plugin
cd ShadeHost-Pelican-Plugin

php dev.php up
```

That's it. On the first start the following happens:

1. The script checks whether `panel.pelican.test` / `wings.pelican.test` resolve to `127.0.0.1`.
   If not, it asks for elevation (UAC on Windows, `sudo` on macOS/Linux) and adds them to the hosts file.
2. PostgreSQL and Redis start.
3. The `provision` container runs migrations and creates the admin user, node, allocations
   and eggs, then writes `data/wings/config.yml`.
4. Panel and Wings start and connect to each other automatically.
5. `dev.php` follows the panel + wings logs so you see what's happening. Press `Ctrl+C` any
   time to stop watching - the stack keeps running. Pass `-d` to stay detached instead.

Afterwards:

|            |                                                             |
| ---------- | ----------------------------------------------------------- |
| Panel      | <http://panel.pelican.test>                                 |
| Login      | `admin@pelican.test` / `password`                           |
| Wings API  | <http://wings.pelican.test:8080>                            |
| SFTP       | `127.0.0.1:2022`                                            |
| PostgreSQL | `127.0.0.1:5432` (`pelican` / `pelican` / database `panel`) |
| Redis      | `127.0.0.1:6379`                                            |

The node shows up in the admin area under **Nodes** and should be **online** right away.

---

## Why a hosts entry is needed

The panel and Wings have to be reachable under the **same name** from two places: from the
browser (console websocket, file manager) and from inside the Docker network (panel → Wings
API, Wings → panel API). That is why `panel.pelican.test` and `wings.pelican.test` are both
hosts entries pointing at `127.0.0.1` and Docker network aliases.

- `.test` is reserved for local testing by RFC 6761, so it never leaks to a public DNS server.
- `.localhost` does **not** work here: libcurl (which the panel uses for its HTTP calls)
  hard-codes `*.localhost` to `127.0.0.1` and would never reach the Wings container.

Remove the entries again with `php dev.php unhosts`.

---

## Commands

```sh
php dev.php up             # Start the stack and follow panel + wings logs
php dev.php up -d          # Same, but stay detached (no log follow)
php dev.php down           # Stop it, keep all data
php dev.php restart panel  # Restart a single service
php dev.php status         # Container status
php dev.php logs           # Follow panel + wings logs
php dev.php logs wings     # Follow a single service

php dev.php artisan p:plugin:list      # Run artisan inside the panel container
php dev.php artisan p:plugin:make      # Scaffold a new plugin
php dev.php shell                      # Shell inside the panel container
php dev.php refresh                    # Clear all caches
php dev.php provision                  # Re-run provisioning
php dev.php wings-config               # Print data/wings/config.yml
php dev.php wings-sync                 # Re-sync the config and restart Wings

php dev.php hosts / unhosts            # Add / remove the hosts entries
php dev.php reset                      # Wipe everything and start over
```

---

## Repository layout

This repository contains **only the development environment**. Each plugin is its own
repository, cloned into `plugins/<plugin-id>/`, and `plugins/*` is gitignored here.

```powershell
git clone <plugin-repo-url> plugins/testapi
```

The folder name must match the `id` field in the plugin's `plugin.json`, so always pass the
target directory explicitly. Provisioning picks up every folder in `plugins/` that contains a
`plugin.json`, so you can work on several plugins side by side without changing anything.

Note that the panel writes the `meta` block in `plugin.json` at runtime (install status), so
that file shows up as modified in the plugin repo. Strip `meta` before publishing.

---

## Plugin development

The plugin lives in `plugins/testapi/` and is mounted live into the container.

**What takes effect immediately, without a restart:**

- PHP classes (controllers, services, models) – OPcache revalidates every file on every request
- Blade views – recompiled whenever they change
- New Filament resources / pages / widgets – the Filament component cache is disabled

All production caches are deliberately off: config, route, event and Filament component
caches are cleared on container start, plus `APP_DEBUG=true`, `APP_ENV=local`,
`LOG_LEVEL=debug`.

**When you do need to do something:**

| Change                                       | Command                                 |
| -------------------------------------------- | --------------------------------------- |
| New migration in the plugin                  | `php dev.php artisan migrate`           |
| New composer dependency in `plugin.json`     | `php dev.php artisan p:plugin:composer` |
| New service provider / changed `plugin.json` | `php dev.php restart panel`             |
| Something is stuck                           | `php dev.php refresh`                   |

Create a new plugin:

```sh
php dev.php artisan p:plugin:make
```

Panel logs: `data/logs/laravel-*.log` or `php dev.php logs panel`.

---

## IDE autocomplete (Filament / Laravel / panel classes)

The plugin only ships its own PHP files (`plugins/testapi/src`) – there is no local `vendor/`,
so out of the box your editor can't resolve `Filament\...`, `Illuminate\...` or the panel's own
`App\...` classes (`App\Models\Server`, `App\Filament\...`, etc.).

`php dev.php up` sets this up on its own the first time - nothing extra to run. It copies the
**exact** `app/`, `vendor/`, `composer.json`/`.lock` and `resources/views` out of the running
`panel` container into `./panel-src/` (gitignored, reference only – nothing in there is
executed or edited). Because it comes straight from the container, it always matches the real
Filament, Laravel and panel version you're running, instead of a guessed tag from GitHub.

`./panel-src/` sits inside this workspace, so VS Code's built-in PHP support and
[Intelephense](https://marketplace.visualstudio.com/items?itemName=bmewburn.vscode-intelephense-client)
(recommended in `.vscode/extensions.json`) pick it up automatically – full autocomplete, hover
docs and go-to-definition for Filament resources/pages/widgets and every panel model/service,
right from `plugins/testapi/src`. `.vscode/settings.json` already excludes
`panel-src/vendor` from search and file-watching so the editor stays fast.

`panel-src/` isn't touched again after that first sync. Run `php dev.php ide-sync` yourself to
refresh it after an image upgrade or `php dev.php artisan p:plugin:composer`. If
`panel-src/composer.json`'s `require.php` differs from `8.3`, update
`intelephense.environment.phpVersion` in `.vscode/settings.json` to match.

**Adding a composer package to the plugin itself:** create `plugins/testapi/composer.json`
with a `require` block, then run `php dev.php artisan p:plugin:composer` (Pelican merges and
installs it into the panel's vendor) followed by `php dev.php ide-sync` to refresh autocomplete.

Since `panel-src/` lives in this workspace, it's also what lets AI tooling (e.g. GitHub
Copilot) search and read the actual panel source when you ask it to build something that
depends on panel internals - no separate setup needed.

---

## Changing the configuration

All defaults live directly in `docker-compose.yml`. To override them:

```powershell
Copy-Item .env.example .env
```

Then edit `.env` (ports, hostnames, admin credentials, allocation range, eggs) and run
`php dev.php up` again. If you changed a hostname, run `php dev.php hosts` afterwards.

---

## Where the data lives

```
data/
  panel/     Panel data (.env with APP_KEY, storage, avatars, icons)
  logs/      Laravel and supervisor logs
  wings/     Wings config.yml
```

`data/wings/config.yml` is the file Wings actually reads and writes. The provisioner seeds
it, then Wings expands it on first boot from ~20 to ~135 lines (Docker network, uids, backup
and SFTP settings). Re-provisioning only patches the keys the panel owns – token, uuid,
ports, `remote` – so both those expansions and your own edits survive. Print it with
`php dev.php wings-config`.

Not in `data/`:

- **PostgreSQL** uses the named volume `pelican-dev_postgres-data` – Postgres refuses to start
  on a Windows bind mount because it cannot enforce `0700` on its data directory there.
- **Game server files** live in `/var/lib/pelican` **inside the Docker VM**, together with
  `passwd`, `group` and `machine-id` in `/var/lib/pelican/etc`.

  Wings does not open those last three itself – it tells the Docker daemon to bind them into
  every game server container as `/etc/passwd`, `/etc/group` and `/etc/machine-id`. The
  daemon resolves paths against the VM, not against the Wings container, so they have to sit
  on a path that means the same thing on both sides. A Windows bind mount would fail with
  `bind source path does not exist`.

  Upstream Wings defaults these to `/etc/pelican`, next to `config.yml`. This setup moves
  them to `/var/lib/pelican/etc` (via `system.user.passwd.directory` and
  `system.machine_id.directory`) so that `/etc/pelican` holds nothing but `config.yml` and
  can stay a normal Windows bind mount you can read and edit.

You can browse the VM paths with:

```powershell
docker run --rm -it -v /var/lib/pelican:/data alpine sh
```

---

## Creating a test server

A _Paper Server_ (egg `Paper`) is created automatically on first provisioning, owned by the
admin account and using the first free allocation on `local-wings`. Find it under
**Admin → Servers** or on the dashboard - it installs itself and is reachable at
`127.0.0.1:<port>` once it shows **running**. Disable this with `PROVISION_SERVER=false` in
`.env`, or change `SERVER_NAME` / `SERVER_EGG` / `SERVER_MEMORY` / `SERVER_DISK` / `SERVER_CPU`
to customize it.

To create additional servers:

1. In the panel go to **Admin → Servers → Create**
2. Egg: _Vanilla Minecraft_ or _Paper_ (both are imported automatically)
3. Node: `local-wings`, allocation: any port from `25565–25580`
4. The server is then reachable at `127.0.0.1:<port>`

---

## Troubleshooting

**`ping panel.pelican.test` fails**
The hosts entries are missing – run `php dev.php hosts`.

**The node shows as "offline" in the panel**
Check `php dev.php logs wings`. Usually the token no longer matches because the database was
reset – fix it with `php dev.php wings-sync`.

**Port 80 is already in use (IIS, Skype, another web server)**
Set `PANEL_PORT=8000` in `.env`, run `php dev.php up`, then use
<http://panel.pelican.test:8000>.

**Provisioning failed**
Look at `docker compose logs provision` and retry with `php dev.php provision` – the script is
idempotent.

**Everything is broken**
`php dev.php reset` deletes the database, `data/` and all game server files.
