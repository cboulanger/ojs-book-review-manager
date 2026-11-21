# Local test environment

A single Podman (or Docker) container with Apache, PHP 8.2, MariaDB and OJS 3.5, preinstalled with a test journal. The plugin directory is bind-mounted into the container, so changes to PHP files, templates and locale files are live without rebuilding.

For local testing only: the passwords are fixed defaults, mail is only logged, and the container listens on 127.0.0.1 only.

## Usage

```sh
dev/ojs-dev.sh up      # builds the image on first use, then starts OJS
dev/ojs-dev.sh logs    # follow Apache/PHP logs
dev/ojs-dev.sh shell   # shell in /var/www/html (e.g. to run mysql ojs)
dev/ojs-dev.sh down    # remove the container and all test data
dev/ojs-dev.sh reset   # start again from a fresh install
dev/ojs-dev.sh build   # rebuild the image (after changing files in dev/)
```

Open <http://localhost:8080/index.php/test>. Set `OJS_PORT` to use a different port.

| Account | Password | Roles |
| --- | --- | --- |
| `admin` | `ojsadmin` | Site admin, journal manager |
| `author` | `ojsauthor` | Author |
| `bre` | `ojsbre` | Book review editor (plugin role, base role Section editor) |
| `reviewer2` | `ojsreviewer2` | Author (a second bidder) |
| `publisher` | `ojspublisher` | Publisher contact at "Test Press" (plugin role; never needs to log in) |

On first start, the container installs the plugin's database tables and enables the plugin in the test journal. Where to find it:

- Manager and book review editor: left menu › Book Reviews (Rezensionen)
- Author: submission wizard, "For the Editors" step

Emails are not sent; they are written to the container log (`dev/ojs-dev.sh logs`), including the one-click links. Emails sent by the scheduled task appear in its output:

```sh
dev/ojs-dev.sh shell
runuser -u www-data -- php lib/pkp/tools/scheduler.php test --name="APP\plugins\generic\bookReviewManager\classes\ReservationReminder"
```

Run it as `www-data`: as root it creates cache files that Apache can't write to afterwards (fix with `chown -R www-data:www-data cache`).

To add the workflow accounts to an existing container: `php tools/devSetup.php users` (after copying `plugins/generic/bookReviewManager/dev/devSetup.php` to `tools/`).

The plugin upgrades its own tables when it loads (`schemaVersion` plugin setting, see `ObjectsForReviewSchemaMigration::SCHEMA_VERSION`), so schema changes also reach existing installations.

## Podman on macOS

The Podman VM must be able to see the plugin directory. By default it shares only `/Users`, `/private` and `/var/folders`. If the code lives elsewhere (e.g. on another volume), recreate the machine with that path shared:

```sh
podman machine rm
podman machine init -v /Users:/Users -v /private:/private -v /var/folders:/var/folders -v /Volumes/Hub:/Volumes/Hub
podman machine start
```

## Files

- `Containerfile`: the image (OJS version in `ARG OJS_VERSION`)
- `build-install.sh`: runs at build time; creates the database, installs OJS, creates the journal and users
- `devSetup.php`: OJS CLI tool used by the scripts (install, journal, enable-plugin)
- `entrypoint.sh`: container start; sets `base_url`/`allowed_hosts`, installs the plugin, runs Apache
- `e2e-sketches/`: ad-hoc Playwright scripts from development, raw material for a future test suite (see its README)
