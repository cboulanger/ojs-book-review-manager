# Book Review Manager for OJS 3.5

An OJS plugin for journals that publish book reviews. It manages the whole path from a work offered for review to the published review: the public list of offers, choosing a reviewer, the review agreement, the review copy and its return, and the link between the reviewed work and the review article.

> **Origin.** Book Review Manager started as a fork of the [Objects for Review plugin](https://github.com/ajnyga/objectsForReview) by Antti-Jussi Nygård, which lets authors attach the metadata of reviewed works to their submissions and lets readers reserve works from a public list. The fork became an almost complete rewrite, so, as agreed with the maintainer of Objects for Review, it continues as a separate plugin. The author-side features are kept, but the behaviour has changed substantially and most of the functionality is new. The concept and its discussion are in [issue #1 of the fork](https://github.com/cboulanger/objectsForReview/issues/1).
>
> Both plugins can be installed in the same OJS, and the data of Objects for Review can be imported, see [Switching over from Objects for Review](#switching-over-from-objects-for-review).

## What the plugin does

Carried over from Objects for Review:

- Authors enter the metadata of the reviewed work (title, authors, publisher, year, identifier) in the submission wizard, or pick a work they reserved.
- Readers see the reviewed work on the article page.
- A public page lists the works offered for review.

Changed or new in Book Review Manager:

- **OJS 3.5 support.** The backend has its own menu section with an overview of the work to be done.
- **DOI and ISBN lookup.** Editors fill in the metadata from Crossref or from the ISBN. DOIs and ISBNs are linked on the public page and on the article page.
- **Book review editor role.** A user group created by the plugin. Each offer has a responsible book review editor who receives its notifications and manages it.
- **Two ways to assign a review, chosen per offer:**
  - *direct reservation*: first come, first served;
  - *bidding*: reviewers apply with an optional statement and deadline, and the editor chooses. Bidders who are not chosen are told so and stay on a waiting list.
- **Review agreement.** Reviewers accept the journal's terms, set a due date and say how they will get the work. Selected bidders get a contract offer with a deadline, a reminder and a one-click link.
- **Review copies.** The plugin tracks each copy from the request to the publisher (or from the journal's stock) to receipt. If no review comes, it tracks the return to the journal or the publisher. After publication, the publisher can be notified.
- **Publisher contacts.** People at publishers are stored as users who never log in. They confirm their steps ("copy sent", "returned copy arrived") with one-click links in the emails.
- **Hiding offers.** A work can be taken off the public list without ending anything, for example while particular people are invited.
- **Inviting reviewers.** Build an invitation list for a work from the journal's users or new people, then invite:
  - one by one, with a personalised email;
  - in a sequential round that moves to the next person automatically after a decline or a passed deadline (direct reservation);
  - or everyone at once (bidding).

  Invitees reserve, apply or decline with a one-click link.
- **Review essays.** Several works can be offered as one review essay, created upfront or by combining works already offered (taking over a reviewer one of them already has). The process with the reviewer (offer, invitations, agreement, due date, submission, publication) runs once for the essay; the review copy and the publisher notification stay with each work. See [the manual](docs/workflow.md#9-review-essays-one-review-of-several-works).
- **Reservation management.** Editors can email reviewers, cancel reservations and see overdue reviews. A daily task sends reminders to reviewers, selected bidders and publishers.
- **Audit trail.** Every step and every email is recorded in the offer's history.
- **Privacy.** Delivery addresses and phone numbers are deleted once the copy has arrived, and bid statements once the review is published. Copies are never forwarded from one reviewer to the next.
- **Editable emails.** All emails are templates under Settings › Emails, in English, German and Finnish.
- **API for imports.** Works (by DOI or ISBN), reviewers, publisher contacts and review processes already under way can be imported with an OJS API key, with a Node command line client. See [skills/book-review-manager-api/SKILL.md](skills/book-review-manager-api/SKILL.md). Review processes kept only in email can be migrated with [skills/book-review-manager-email-import](skills/book-review-manager-email-import/SKILL.md).

## Documentation

- [Setting up the plugin](docs/setup.md): for journal managers and site administrators.
- [The review workflow](docs/workflow.md): manual for book review editors, with flowcharts and screenshots.

## Installation

Requirements: OJS 3.5 and PHP 8.2 or later.

1. Get the plugin, either by:
   - downloading `bookReviewManager-v….tar.gz` from the [latest release](https://github.com/cboulanger/ojs-book-review-manager/releases/latest) and uploading it under *Website Settings › Plugins › Upload a new plugin* (or unpacking it into `plugins/generic/`);
   - or cloning it into the OJS plugin folder (this includes the development files):

     ```sh
     cd /path/to/ojs/plugins/generic
     git clone https://github.com/cboulanger/ojs-book-review-manager.git bookReviewManager
     ```

     The folder must be called `bookReviewManager`.

2. If you unpacked or cloned the plugin, register it from the OJS folder:

   ```sh
   php lib/pkp/tools/installPluginVersion.php plugins/generic/bookReviewManager/version.xml
   ```

3. Enable *Book Review Manager* in each journal under *Website Settings › Plugins › Generic Plugins*. This creates the user groups "Book review editor" and "Publisher contact" and installs the email templates.
4. Make sure OJS runs its scheduled tasks, because the reminders depend on them. See [Setting up the plugin](docs/setup.md#scheduled-tasks).

To **update**, upload or unpack the new release (or pull the new version). The plugin upgrades its database tables when it loads.

## Switching over from Objects for Review

Install Book Review Manager, then copy the data from the OJS folder:

```sh
php plugins/generic/bookReviewManager/tools/importObjectsForReview.php --dry-run   # show what would be copied
php plugins/generic/bookReviewManager/tools/importObjectsForReview.php [journalPath ...]
```

This copies the works, journal settings and customised email templates. Reservations become assignments of the reviewer, and works attached to a submission become submitted reviews. The data of Objects for Review is left unchanged, so both plugins can run side by side during the transition, and the import can be repeated (works imported before are skipped). While Objects for Review is enabled in a journal, its public page keeps the address `for-review`.

## For developers

The [`dev/`](dev/) folder has a Podman (or Docker) setup that runs OJS 3.5 with a test journal in a single container. The plugin folder is mounted into the container, so changes to PHP, templates and locale files take effect without a rebuild.

```sh
dev/ojs-dev.sh up      # builds the image on first use, then starts OJS on http://localhost:8080/index.php/test
dev/ojs-dev.sh logs    # Apache/PHP log; sent emails appear here, including the one-click links
dev/ojs-dev.sh shell   # shell in the OJS folder
dev/ojs-dev.sh reset   # start again from a fresh install
dev/ojs-dev.sh build   # rebuild the image after changing files in dev/
```

The test journal has accounts for each role: admin, an author, a book review editor, a second reviewer and a publisher contact. Each password is `ojs` followed by the user name, for example `ojsbre`. [dev/README.md](dev/README.md) lists the accounts and explains how to run the daily task by hand and how to share a folder outside `/Users` with the Podman VM on macOS.

Use `OJS_PORT` to run it on another port. Releases: see [dev/README.md](dev/README.md#releases). Tests: `tests/run.sh` runs the PHP unit tests (PHPUnit in the container), the unit tests of the API client, and the API end-to-end tests against the container. See [tests/README.md](tests/README.md), which also explains how to add suites (e.g. browser tests).

How the code is organised:

| Path | Contents |
| --- | --- |
| `classes/AssignmentWorkflow.php` | Offer states and the bidder/reviewer transitions |
| `classes/CopyWorkflow.php` | Review copy states and steps |
| `classes/ActionLink.php` | One-click links (hashed, expiring, single use) |
| `classes/ReservationMailer.php`, `mailables/` | Emails and their templates |
| `classes/ReservationLog.php` | Audit trail (OJS event log) |
| `classes/ReservationReminder.php` | Daily task |
| `pages/` | Public page, one-click link pages, backend pages (To-do, Reviewers & bidders, ...) |
| `controllers/` | Grids and modal forms |
| `api/` | REST API controller (`/api/v1/bookReviewManager`) and the Node client `ofr` (see `skills/book-review-manager-api/SKILL.md`) |
| `skills/` | Agent skills: using the API, and migrating review processes from exported emails |
| `tests/` | Test suites and their runner (`tests/run.sh`) |
| `ObjectsForReviewSchemaMigration.php` | Tables. Raise `SCHEMA_VERSION` when changing it |
| `classes/UpstreamImport.php`, `tools/` | Import from Objects for Review |

Strings live in `locale/{en,de,fi}/locale.po`, and email templates in `emailTemplates.xml` and `locale/*/emails.po`.

## License

This plugin is licensed under the GNU General Public License v3. See the file LICENSE for the complete terms of this license.

The public list uses [tablesort](https://github.com/tristen/tablesort) for sorting, included under the MIT license ([licence](https://github.com/tristen/tablesort/blob/gh-pages/LICENCE)).
