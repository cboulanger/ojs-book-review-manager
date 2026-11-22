# Setting up the Book Review Manager plugin

For journal managers and site administrators. To install the plugin, see the [README](../README.md#installation). For daily work with the plugin, see [The review workflow](workflow.md).

## Checklist

1. [Enable the plugin](#enable-the-plugin) in the journal.
2. Make sure [scheduled tasks](#scheduled-tasks) run.
3. Add [book review editors](#book-review-editors).
4. Add [publisher contacts](#publisher-contacts).
5. Fill in the [plugin settings](#plugin-settings): terms, due dates, reminders, defaults.
6. Review the [email templates](#email-templates).
7. Link the [public page](#the-public-page) from the journal's menu.

## Enable the plugin

Under *Website Settings › Plugins › Generic Plugins*, enable **Book Review Manager**. Enabling it:

- creates two user groups in the journal: **Book review editor** and **Publisher contact**;
- installs the email templates (templates added by later versions are installed when they are first needed);
- adds the menu section *Book Reviews* (German: *Rezensionen*) to the backend, for journal managers and book review editors.

The backend menu section has these pages:

| Page | Purpose | Who sees it |
| --- | --- | --- |
| Offered works | Create and edit offers | managers, book review editors |
| To-do | Everything that needs a decision or is waiting for someone | managers, book review editors |
| Reviewers & bidders | All bidders and reviewers, with filters | managers, book review editors |
| Reviewed works | Works attached to review submissions | managers, book review editors |
| Settings | Plugin settings | managers only |
| Public page | Opens the public list in a new tab | managers, book review editors |

## Scheduled tasks

A daily task:

- reminds reviewers before their review is due;
- reminds selected bidders who have not answered a contract offer, and expires offers that are not answered in time;
- reminds publishers of unanswered copy requests;
- handles invitations: reminds invitees, ends unanswered ones and, in sequential rounds, invites the next person.

OJS 3.5 runs it through its task scheduler. The recommended setup is a cron job on the server that runs the scheduler every minute:

```cron
* * * * * cd /path/to/ojs && php lib/pkp/tools/scheduler.php run >> /dev/null 2>&1
```

Run it as the web server's user (for example in that user's crontab). Otherwise the task creates cache files the web server can't write to.

The alternative is to set `task_runner = On` in the `[schedule]` section of `config.inc.php`, so tasks run at the end of web requests. This is simpler but less reliable on quiet sites. See the [PKP admin guide](https://docs.pkp.sfu.ca/admin-guide/en/deploy-scheduled-tasks) for details.

To run the task once by hand, for example to test it:

```sh
php lib/pkp/tools/scheduler.php test --name="APP\plugins\generic\bookReviewManager\classes\ReservationReminder"
```

## Book review editors

Book review editors manage offers, choose reviewers, handle review copies and receive the notifications for their offers. Add users to the group **Book review editor** under *Users & Roles*.

- The group's base role is *Section editor*. A book review editor can therefore also be assigned to the submissions of a book review section, and manage the reviews themselves in the editorial workflow. Using such a section is a convention; the plugin does not require one.
- Journal managers can do everything book review editors can, and they also change the plugin settings.
- Each offer has a **responsible book review editor** who receives its notifications. If an offer has none, notifications go to the notification email from the settings, or to the journal's primary contact.
- **Mailing address:** if the journal keeps review copies in stock, printed copies are returned to the responsible editor. That editor must therefore enter a mailing address in their profile. The offer form checks this.

## Publisher contacts

Publisher contacts are the people at publishers who send review copies. They are ordinary OJS users in the group **Publisher contact** (base role *Reader*), but they never need to log in. Every step they take is a one-click link in an email.

Create one user per contact under *Users & Roles › Add user*:

- **Name and email address**: the emails go to this address.
- **Affiliation**: the name of the publisher. It is shown next to the contact's name.
- **Mailing address**: required if printed copies may be returned to the publisher.
- **User group**: *Publisher contact*.

OJS requires a user name and password. Choose any, and don't send a welcome email if the contact doesn't need one.

## Plugin settings

Open *Book Reviews › Settings*, or the plugin's *Settings* link in the plugin list.

![Plugin settings](images/settings.png)

| Setting | Effect |
| --- | --- |
| Emails: Do not send emails | **On after installation.** The plugin then sends no emails at all (messages, reminders, notices to reviewers, publishers and editors). They are recorded in each work's history, marked *[not sent]*, and the daily task (reminders, expiring offers and invitations) pauses. A notice on every *Book Reviews* page and in the email forms says that emails are off. Switch emails on once the data is complete, e.g. after importing existing review processes. |
| Only allow objects created by the manager | Authors can only attach works the journal has offered, not enter other works themselves. |
| Display a list of reviewed objects under the abstract | Shows the reviewed work on the article page. |
| Email for notifying reservations and cancellations | Fallback recipient of the notifications for offers without a responsible book review editor. If empty, the journal's primary contact is used. |
| Public instructions | Text at the top of the public page. |
| Terms of reservation | The review agreement. Reviewers must accept it when they reserve a work or accept a contract offer. |
| Review due date: Default / Maximum | Months after the reservation. Reviewers choose a due date up to the maximum. |
| Reminder | Days before the due date on which reviewers are reminded (0: no reminder). |
| Default assignment for new offers | *Direct reservation* or *Bidding*. Can be changed for each offer. |
| Default responsible book review editor | Preselected for new offers. If none is set, the book review editor who creates an offer becomes responsible for it. |
| Contract offers: Days to answer / Reminder after | How long a selected bidder has to accept the agreement (default 14 days), and when they are reminded (default after 7 days; 0: no reminder). |
| Reminders to publishers | Days after which an unanswered copy request is repeated to the publisher contact, and the interval between reminders (default 14; 0: no reminders). |
| Invitations: Days to answer / Reminder after | Days invitees have to answer an invitation (default 7; invitations to apply for an offer with a bid deadline use that deadline), and after how many days they are reminded (default 0: no reminder). |

## Email templates

All emails are editable under *Settings › Emails*. They are in English, German and Finnish; add translations for other languages there. Most emails are prefilled and can be edited before sending. The automatic ones are sent as they are.

| Template | Sent to | When |
| --- | --- | --- |
| `BRM_NEW_RESERVATION` | book review editor | A work was reserved or a contract accepted |
| `BRM_CANCEL_RESERVATION` | book review editor | A reviewer withdrew from a review |
| `BRM_BID_NEW` | book review editor | A new application arrived |
| `BRM_CONTRACT_DECLINED` | book review editor | A selected bidder declined, or the offer expired |
| `BRM_CONTRACT_OFFER` | selected bidder | The editor chose them (with the agreement link) |
| `BRM_CONTRACT_OFFER_AGAIN` | bidder on the waiting list | The work is available again and the editor chose them |
| `BRM_CONTRACT_OFFER_REMINDER` | selected bidder | Automatic reminder before the answer deadline |
| `BRM_BID_UNSUCCESSFUL` | other bidders | Someone else was chosen; they stay on the list |
| `BRM_CONFIRMATION` | reviewer | Automatic confirmation after accepting the agreement, with the "I cannot write this review" link |
| `BRM_REMINDER` | reviewer | Automatic reminder before the due date |
| `BRM_MESSAGE` | reviewer or bidder | A free message from the editor |
| `BRM_CANCEL_BY_EDITOR` | reviewer | The editor cancelled the reservation |
| `BRM_COPY_REQUEST` | publisher contact | Request for a review copy, with the delivery details and a "copy sent" link |
| `BRM_COPY_REQUEST_REMINDER` | publisher contact | Automatic reminder of an unanswered copy request |
| `BRM_COPY_SENT` | reviewer | The copy is on its way, with a "received" link |
| `BRM_RETURN_REQUEST` | reviewer | Please return the printed copy, with the return address and a "sent back" link |
| `BRM_RETURN_SENT` | publisher contact | The copy is on its way back, with a "returned copy arrived" link |
| `BRM_PUBLISHER_NOTIFICATION` | publisher contact | The review has been published |
| `BRM_INVITATION_RESERVE` | invitee | Invitation to review a work with direct reservation (with the invitation link) |
| `BRM_INVITATION_BID` | invitee | Invitation to apply for the review of a work offered for bidding (with the invitation link) |
| `BRM_INVITATION_DECLINED` | book review editor | An invitee declined, or did not answer by the deadline (not during sequential rounds) |
| `BRM_INVITATION_REMINDER` | invitee | Automatic reminder of an unanswered invitation (if set up) |
| `BRM_INVITATION_EXHAUSTED` | book review editor | A sequential round reached the end of the list without a reservation |

Some values are only filled in when an email is sent: the one-click links (`{$contractUrl}`, `{$copySentUrl}`, `{$receivedUrl}`, `{$returnSentUrl}`, `{$returnReceivedUrl}`, `{$retractUrl}`, `{$removeUrl}`, `{$invitationUrl}`), the recipient's name (`{$recipientName}`) and the return address (`{$returnAddress}`). Keep these placeholders when editing a template. Contract offers and invitations can't be sent without their link placeholder; in the other emails, a missing link is added at the end.

## The public page

The list of works offered for review is at `https://<your-journal>/books-for-review`. To add it to the journal's menu, go to *Website Settings › Navigation*, add a menu item of the type **Works for review**, and place it in the primary navigation menu.

## One-click links

Links in emails let reviewers and publisher contacts act without logging in: accept or decline a contract offer, leave the waiting list, confirm a review copy step, withdraw from a review.

- Opening a link never changes anything; the page asks for confirmation first. This also keeps email security scanners, which open links automatically, from triggering actions.
- Each link can be used once, and expires after one year at the latest. Links that are no longer needed, for example when a bidder leaves the list, stop working.
- Only a hash of each link is stored in the database.

## Personal data

- Delivery addresses and phone numbers are deleted when the reviewer confirms receipt of the copy, or when the review is attached to a submission.
- Bid statements are deleted when the review is published or the offer is closed.
- The offer's history keeps names and the emails that were sent, as an audit trail.
- Review copies are never forwarded from one reviewer to the next. They always go back to the journal or the publisher, so reviewers' addresses are never passed on to other reviewers.
