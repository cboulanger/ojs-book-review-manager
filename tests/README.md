# Tests

```sh
dev/ojs-dev.sh up          # the dev container (needed by everything except unit/client)
tests/run.sh               # all suites
tests/run.sh unit          # one layer
tests/run.sh e2e/api       # one suite
tests/run.sh --list        # the suites
tests/run.sh unit/php -- --filter IsbnLookupTest   # arguments after -- go to each suite
```

`tests/run.sh` exits with 0 if every selected suite passed, 1 if one failed, and 2 for a usage error. A suite's own `run.sh` exits with 3 if a prerequisite is missing (container not running, Node too old); the runner counts that as a failed suite.

## Suites

| Suite | What it tests | Runs | Needs |
| --- | --- | --- | --- |
| `unit/php` | The plugin's PHP classes: lookups and identifier normalization, offer states, reservation validation, review copy steps, one-click links, API controller helpers, locale files | PHPUnit 11.5 inside the container, with OJS booted (database, translations) | Container |
| `unit/client` | The Node client and the `ofr` CLI (`api/test/`) | `node --test`, offline (mocked fetch, fake API server) | Node 20+ |
| `e2e/api` | The REST API end to end: authentication and roles, lookups, works, people, review processes, bulk import (client and CLI), no emails sent, history entries | `node --test` against `http://localhost:8080/index.php/test` | Container, Node 20+, network for lookups |

### unit/php

- Tests are in `tests/unit/php/*Test.php`, in the namespace `APP\plugins\generic\bookReviewManager\tests\unit`, and extend `TestCase`.
- `TestCase` has `object()` and `assignment()` (unsaved data objects), `plugin()` (a `FakePlugin` with settings), and `invoke()` (calls a protected method).
- `bootstrap.php` boots OJS the way its command line tools do, with English as the locale.
- Tests that write to the database are in the `db` group. They clean up after themselves. Run without them: `tests/run.sh unit/php -- --exclude-group db`.
- A few tests use dev journal accounts (`publisher`). If those are missing, the tests are skipped rather than failed.
- PHPUnit is pinned (`PHPUNIT_VERSION` in `lib/env.sh` and `dev/Containerfile`). For older images, `run.sh` installs it in the container.

### e2e/api

- Each test file covers one area. `fixtures.mjs` has clients per role (`as('admin' | 'bre' | 'author')`), `raw()` for requests without the client, `rejects(promise, status, code)`, and factories (`newWork`, `newBiddingWork`, `newUser`, `newPublisherContact`).
- **Test data is unique per run.** It uses random valid ISBNs, DOIs with the test prefix `10.5555`, and `e2e-*@example.org` addresses. So the suite needs no reset, can be repeated, and its files run in parallel. Works are created hidden, so they stay off the public list.
- **The suite leaves no data behind.** `run.sh` dumps the database before the tests and restores it when they end, also after a failure or Ctrl-C, and then clears OJS's data caches. Changes made in the dev journal by hand during a test run are lost too. If a run was killed so hard that it couldn't restore (e.g. `kill -9`), the next run restores the leftover snapshot first. `OFR_E2E_KEEP=1` keeps the test data, e.g. to look at it in the browser. The helpers are `db_snapshot` and `db_restore` in `lib/env.sh`.
- `OFR_E2E_OFFLINE=1` skips the tests that query Crossref and Open Library.
- `run.sh` sets `api_key_secret` and passes the Authorization header through Apache in containers built before the API existed.
- Two tests are skipped in the standard dev journal: attaching an imported review to a submission (it has no submissions), and refusing a section editor who isn't a book review editor (it has no such account). Add that data and they run.

## Shared helpers (`lib/`)

| File | For |
| --- | --- |
| `env.sh` | Shell suites: `PODMAN`, `OFR_CONTAINER`, `OFR_E2E_URL`, `in_container`, `require_container`, `require_node`, `ensure_phpunit`, `ensure_api_ready`, `db_snapshot` / `db_restore` |
| `ojs.mjs` | JS suites: `exec()` and `sql()` in the container, `apiKey(username)`, `mailsSince(timestamp)` (emails from the container log), `history(objectId)` (event log), unique data (`uid`, `email`, `isbn13`, `isbn10`, `doi`, `date`), dev accounts (`USERS`) |
| `php/api-key.php` | Prints a user's API key (reuses an existing one, so parallel processes agree) |

Configuration through the environment: `OFR_CONTAINER` (default `ojs-bookreviewmanager`), `PODMAN` (default `podman`), `OFR_E2E_URL` or `OJS_PORT`, `OFR_E2E_OFFLINE`, and `OFR_E2E_KEEP`.

## Adding a suite

A suite is a directory `tests/<layer>/<name>/` with an executable `run.sh`. `tests/run.sh` finds it; nothing else has to be registered. The `run.sh`:

1. sources `../../lib/env.sh` and checks its prerequisites (`require_container`, `require_node`, …);
2. runs the tests, passing on its arguments (`"$@"`);
3. exits with 0 if they passed, 3 if a prerequisite is missing, and another non-zero code if they failed.

### Example: UI tests with Playwright (`tests/e2e/ui`)

The ad-hoc scripts in `dev/e2e-sketches/` (see its README) list the scenarios, selectors and tricks for this. A suite could look like this:

```text
tests/e2e/ui/
  package.json        { "type": "module", "devDependencies": { "@playwright/test": "<pinned version>" } }
  playwright.config.mjs   baseURL from OFR_E2E_URL, workers as needed
  login.mjs           storage states per dev account (USERS in lib/ojs.mjs has the passwords)
  reserve.spec.mjs    ...
  run.sh
```

```sh
#!/bin/bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../lib/env.sh"
require_node
require_container
cd "$TESTS_DIR/e2e/ui"
[ -d node_modules ] || npm ci
npx playwright test "$@"
```

Reuse `lib/ojs.mjs` for setup and checks that are faster without the browser. For example, create the work through the API, then test the reservation dialog in the browser, and check the email with `mailsSince()` and the history with `history()`. Use the same convention for test data: unique values per run, hidden works unless the test is about the public list.
