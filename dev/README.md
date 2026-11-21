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

## Releases

The release package contains only what OJS needs (a rule of the PKP Plugin Gallery). What is left out is marked `export-ignore` in [`.gitattributes`](../.gitattributes): `dev/`, `tests/`, `docs/`, `skills/`, the Node API client, and everything of tablesort except its script.

1. Set `<release>` (four numbers) and `<date>` in `version.xml` and commit.
2. Optional: check the package locally with `dev/package.sh` (writes `dist/`). It fails if the package lacks a runtime file or contains development files.
3. Push an annotated tag matching the version (`1.0.1.0` → `v1.0.1`, `1.0.1.2` → `v1.0.1-2`). Its first line becomes the release description in the gallery:

   ```sh
   git tag -a v1.0.1 -m "What changed, in one line" && git push origin v1.0.1
   ```

4. The [Release workflow](../.github/workflows/release.yml) runs the checks, builds the package and creates a **draft** release with `bookReviewManager-v1.0.1.tar.gz`, its `.md5`, `gallery-release.xml` and `gallery-plugin.xml`. Drafts are only visible to the repository's maintainers: download the package with `gh release download v1.0.1 -p '*.tar.gz'`, test it, then publish the draft on GitHub.
5. Plugin Gallery: open a pull request on [pkp/plugin-gallery](https://github.com/pkp/plugin-gallery) that adds `gallery-release.xml` to the plugin's entry in `plugins.xml` (the first time: `gallery-plugin.xml`, the whole entry). Disclose AI-assisted work in the pull request. A package in the gallery must never change (its md5 is checked), so fixes need a new version.

### Updating a server from a release

After the release is published, from the OJS folder (as a user who may write `plugins/generic/`):

```sh
TAG=v1.0.1; FILE=bookReviewManager-$TAG.tar.gz
URL=https://github.com/cboulanger/ojs-book-review-manager/releases/download/$TAG
curl -fsSLO "$URL/$FILE" && curl -fsSL "$URL/$FILE.md5" | md5sum -c -
mv plugins/generic/bookReviewManager ~/bookReviewManager.previous   # if installed; keep it until the update works
tar xzf "$FILE" -C plugins/generic/
php lib/pkp/tools/installPluginVersion.php plugins/generic/bookReviewManager/version.xml   # as the web server user
```

Reload PHP-FPM (or Apache) afterwards, so OPcache does not mix old and new files. The plugin upgrades its tables on the first request.
