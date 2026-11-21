# E2E sketches

Ad-hoc [Playwright](https://playwright.dev/) scripts written while developing the review workflow (issues #1 and #2). They drove the browser through each feature in the [dev container](../README.md) and printed what they found. They are kept as raw material for a real test suite. **They are not tests:**

- They print values (`console.log`) for a person to read; nothing fails automatically.
- They expect the data of the dev container *as it was at the time*: the accounts from `dev/devSetup.php users` (some renamed to Anna Weber, Jonas Becker, Clara Lindqvist), offers created by earlier scripts, and leftovers from earlier runs. Many only work in a certain order, and running one twice usually fails, because the first run changed the data.
- They use the plugin's current names: URLs (`manageBookReviews`, `books-for-review`), CSS classes (`ofr…`), tables (`brm_objects_…`), email subjects. These will change when the plugin is renamed.

The real test suites are in [`tests/`](../../tests/README.md); browser tests belong in `tests/e2e/ui` (see "Adding a suite" there). What is worth reusing: the helpers in `lib.mjs`, the selectors, the tricks for forcing time-dependent cases, and the list of scenarios below.

## Running

Requirements: Node.js, a local Google Chrome (`channel: 'chrome'`), and the dev container running (`dev/ojs-dev.sh up`).

```sh
cd dev/e2e-sketches
npm install
node p1b.mjs
```

Screenshots written by the scripts land in this folder (git-ignored). The `doc*.mjs` scripts write to `docs/images/`.

## Helpers (`lib.mjs`)

| Helper | What it does |
| --- | --- |
| `login(user, password)` | New browser context, logged in; dialogs (`confirm()`) are accepted automatically |
| `anonymous()` | New context without login (for one-click links) |
| `since()` / `mails(timestamp)` | Emails sent since a moment, parsed from the container log (`podman logs`): `[{to, subject, body}]` |
| `linkIn(text)` | The first one-click link (`books-for-review/link/<key>`) in an email |
| `modalForm(page)` | Waits for the plugin's email modal (`#ofrReservationEmailForm`) |
| `bodyText(page)` | Body of that modal, read from TinyMCE |

Most scripts also use a small `sql()` helper (`podman exec … mysql`) to look up IDs and check stored data.

## Tricks and gotchas

- **Time-dependent cases** (deadlines, reminders, expiry): move the stored date back with SQL (`invitationDeadline`, `dateInvited`, `contractDeadline`, `dueDate`, …), then run the daily task directly:
  `podman exec -w /var/www/html ojs-bookreviewmanager runuser -u www-data -- php lib/pkp/tools/scheduler.php test --name="APP\plugins\generic\bookReviewManager\classes\ReservationReminder"`.
  Its output contains the emails it sent. Run it as `www-data`; as root it creates cache files Apache can't write to (`chown -R www-data:www-data cache` fixes that).
- **Plugin settings are cached** by OJS (Laravel cache). Change them through the settings form, not with SQL, or the change is not seen.
- **Grid row actions** (Edit, Hide, Invite reviewers, …) are behind the arrow at the start of the row: click `a.show_extras`, then the link in `#<row id>-control-row`. Link texts have surrounding whitespace; match with `/^\s*Hide\s*$/`.
- **Rich text fields** are TinyMCE editors: set them with `tinymce.get(id).setContent(…)` plus `fire('change')`.
- **Browser validation** (`type="email"`) blocks invalid input before the server sees it; to test server-side validation, use input the browser accepts.
- **Email log timestamps** are only precise to the second: pick emails by recipient or subject, not by order.
- **Smarty templates are compiled and cached**: after changing templates or locale files, clear `cache/t_compile` in the container if changes don't show.
- **Many data changes reach the history**: `event_log` with `assoc_type = 0x1F0F001` (see `ReservationLog`); emails are in `email_log`.

## Scenarios

| Script | Covers |
| --- | --- |
| `manager.mjs`, `author.mjs`, `isbn.mjs` | Entering works: DOI and ISBN lookup (editor and author forms), identifier links |
| `menu.mjs` | Backend menu section and its pages |
| `settings2.mjs` | Plugin settings: defaults, validation, effect on the reservation dialog |
| `reserve.mjs` | Direct reservation dialog: terms, due date range, copy preference, validation |
| `manage1.mjs`, `manage2.mjs`, `cancelnomail.mjs` | Emailing reviewers, cancelling a reservation (with and without email), history, due-date reminder setting |
| `p1a.mjs` | Book review editor role; creating a bidding offer |
| `p1b.mjs` | Bids, choosing a bidder, contract offer, contract page via link |
| `p1c.mjs` | Contract link: validation, accept, editor notice, link used once |
| `p1d.mjs` | Dropout after cancellation, choosing again ("offer available again") |
| `p1e.mjs`, `p1f.mjs` | Reopening bidding; contract offer reminder and expiry (daily task) |
| `p1g.mjs` | Reviewers & bidders page, status filter |
| `p1h.mjs` | Settings validation (contract days, reminder) |
| `p2a.mjs`, `p2b.mjs`, `p2c.mjs` | Review copies: publisher contact, request, sent, received, withdraw, return to journal or publisher, publisher notification |
| `p3a.mjs`, `p3b.mjs` | One-click links in the copy workflow (publisher and reviewer), automatic follow-up emails |
| `p3c.mjs` | Publisher reminders (daily task), retract link |
| `hide.mjs` | Hide/Show: public page for visitors and reviewers, refused reservation, history |
| `delete.mjs` | Delete: CSRF token and journal checks |
| `invite.mjs` | Invitation list (address book, new person, reorder by button and drag and drop), manual invitations: decline, reserve |
| `invite2.mjs` | Invitations for a bidding offer: apply, expiry (daily task), invite again, closing the offer |
| `round.mjs`, `round2.mjs` | Sequential round: start (hidden), decline and expiry move on, reminder, list exhausted, continue, stop, ended by a public reservation |
| `parallel.mjs` | All at once (bidding), To-do section, selecting a bidder ends the round, stop from To-do |
| `regress.mjs` | Public bid and reservation after the invitation changes |
| `search.mjs` | Address book search, including an empty search |
| `doc1.mjs`, `doc2.mjs`, `doc3.mjs`, `invshot.mjs` | Screenshots for the documentation (`docs/images/`) |
