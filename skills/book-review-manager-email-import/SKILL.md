---
name: book-review-manager-email-import
description: Use when book review processes (review copies, reviewer agreements, deadlines, submitted reviews) exist only as email correspondence and should be migrated into the OJS "Book Review Manager" plugin, especially when mails are exported as .eml files (eM Client, Thunderbird, Outlook, Apple Mail) and personal data must not reach the AI model, or the mail text should only be read by a privacy-compliant OpenAI-compatible endpoint (a research computing centre, a self-hosted model) while a stronger model orchestrates.
---

# Importing review processes from exported emails

> **Draft.** Written before the first real migration. Update it with what that run teaches.

## Overview

You reconstruct the state of each review process from a folder tree of `.eml` files, write a cases file, and import it through the Book Review Manager API (skill `book-review-manager-api`, client `api/bin/ofr.js`).

**Two modes.** In **mode A (direct)** you read the masked mails yourself. In **mode B (delegated)**, a model on an OpenAI-compatible endpoint chosen by the user for its privacy guarantees reads them. Examples are a research computing centre or a self-hosted vLLM. That model reads one case per request and returns structured JSON. You orchestrate: you define the cases, merge the results into the cases file, look up identifiers, ask the user, convert and import. You never see mail text. Mode B also works better with open models: in tests they failed at the agent role (losing track, re-reading, planning instead of working) far more than at reading one case.

**Core principle: you only ever see pseudonyms.** `scripts/eml.js` replaces every person locally with a stable token (`P0012`). That covers names, email addresses, phone numbers and postal-address lines, in headers, subjects, folder names, attachment names and body text. The real values and the mail file paths are kept in `<work>/private.json`. `scripts/cases-to-import.js` puts them back only in the import file.

## Privacy contract

| You may | You never |
| --- | --- |
| Run `eml.js` subcommands and read their output | `cat`, `grep`, `head` or open anything in the mail directory |
| Read `<work>/index.json` and `<work>/cases.json` | Read `<work>/private.json` or the generated `import.json` |
| Run `cases-to-import.js` (its messages use tokens) | Print `ofr` results unmasked: pipe them through `eml.js mask <work>` |
| Run `ofr lookup` (public bibliographic data) | Ask the user to paste real names or addresses into the chat |

**In mode B** don't use `show` or `grep` (they print mail text), and don't read `index.json`. Use `folders`, `threads`, `people`, `extract.js list` and the extraction results. The results are validated and cleaned of private data before they reach you. Each record says which values were removed (`removed`); if any were, tell the user.

**People who never appear in a mail header** (third parties named in the text, signatures, addresses written out) are only masked with **`index --ner`**. That flag runs a local NER model (GLiNER multilingual PII, ONNX on the CPU, about 0.2 s per mail). Each name it finds becomes a name-only token, and each street address or phone number becomes `[address]` / `[phone]`. All of this is stored in `private.json`, so `show`, `grep` and `mask` don't need the model. One-time setup, network only for downloads: `cd ner && npm install && ./download-model.sh` (1.2 GB, full precision; the int8 build is useless).

**Without `--ner`, message bodies are never shown.** `show` prints only the masked header data (date, tokens for sender and recipients, subject, folder, attachment names), and `grep` searches only subjects. That gives much worse results, but by design the tool doesn't let private correspondence reach a commercial model with only header-based masking. ISBNs and DOIs found in bodies still go into the index, since they are bibliographic data, not personal data. If `--ner` can't run, tell the user and work from headers only. Don't look for a way around it.

**How names are matched.** Correspondents (from mail headers) are masked in every form of their name, everywhere. Names found only by NER are masked as full names; their single words are not masked on their own if they are common words: words your mails use in lowercase ("ich", "dann", "field", computed locally) and a fixed list of function words and review vocabulary. After an honorific ("Herr", "Frau", "Dr.", "Lieber", "Dear", …) such a word is masked again. The remaining risk: a third party with a surname that is a common word, named by surname alone. Addresses that differ only in spelling (case, a trailing period, `mailto:`) belong to one person: the later token becomes an alias (`aliasOf`) of the earlier one, and both stay valid. When you see a name or address that slipped through, tell the user at once and mask it (`add-name`, then `index --reuse-ner`).

NER is not perfect: it can miss inflected or unusual names (e.g. a genitive "Luhmanns"). Organizations are deliberately not masked. Missed names can be added to `private.json` by the user, e.g. `{"id": "P9001", "emails": [], "names": ["Jane Doe"]}`, followed by a re-index into the same work dir (tokens stay stable). Email domains are shown, to tell publishers from universities; `--hide-domains` turns that off.

## Workflow

0. **Warn the user about the inference endpoint, before anything else.** Say this explicitly and wait for their confirmation:

   > Masking reduces what reaches the AI model, but it does not make the processing lawful. The mails contain personal data of third parties: correspondents, reviewers, publisher staff. Even the masked text (tokens, dates, subjects, book titles, institutional email domains, the course of a conversation) can still be personal data under the GDPR, because people can be re-identified from context. Before continuing, make absolutely sure that the inference endpoint you use complies with the GDPR and any other applicable privacy rules. That means: a legal basis for the processing; a data processing agreement with the provider; no use of the data for training (check the account's privacy settings and contract terms); retention and deletion terms you accept; and safeguards for any transfer outside the EU/EEA. If in doubt, ask your data protection officer, or use a model that runs entirely locally.

   Don't run `index` or show any mail data until the user confirms.
   Then ask which mode to use. Recommend **mode B** if the user has access to an OpenAI-compatible endpoint whose operator guarantees what step 0 requires. That means no training on the data and no retention beyond the request, and for European journals processing in the EU under a data processing agreement. Mode A sends the masked mail text to the endpoint you run on.

   **For mode B, ask the user to store the API key in a file** before anything else, e.g. `~/.config/book-review-manager/llm-key` with mode 600, outside any git repository. Never ask them to paste the key into the chat, and never print it (`cat`, `echo`, logs). Ask for the base URL (e.g. `https://chat-ai.academiccloud.de/v1`) and which models the endpoint offers, then pick a model. Prefer a strong instruct or reasoning model with JSON output. Verify the setup with synthetic data only:

   ```sh
   export OFR_LLM_BASE_URL=<base URL> OFR_LLM_KEY_FILE=<key file>
   node scripts/extract.js check --model <name>      # "ok, JSON answer"
   ```
1. **Export** (user). Export the whole workflow folder tree as `.eml` files, keeping the folder structure. In eM Client that's *File › Export › EML*, ticking the folders; there's no mbox export. Any tree of `.eml` files works.
2. **Index**: `node scripts/eml.js index <mail dir> --out <work dir> --ner`. Use a work dir outside any git repository. The command prints counts only. Check that the "me" token is the journal's address (`--me addr1,addr2` otherwise; the user types the addresses, you don't need to see them).
3. **Learn the user's workflow.** Run `folders` and ask the user what each folder means. The skill doesn't assume a structure. Example tree and mapping:

   | Folder (example) | Meaning | Import as |
   | --- | --- | --- |
   | `0 Acquisition` | Asking publishers for copies | nothing, or the work with `hidden: true` |
   | `1 Proposals` | Books offered or proposed | the work, no assignment |
   | `1 Proposals/_Declined` | Not to be reviewed | skip |
   | `2 Requested/<case>` | Reviewer asked, no answer yet | the work only; invitations can't be imported, so note it for the user |
   | `3 Assigned/<reviewer> - <author>` | Reviewer agreed, writing | `accepted` + `dueDate` + review copy status |
   | `4 Submitted/<case>` | Review is in OJS | `submitted` + `submissionId` (ask the user) |
   | `5 Archive/<year>/<case>` | Published | skip, or `completed` if the history is wanted |
   | `5 Archive/_Not realized/<case>` | Abandoned | skip |
   | `General/Publisher contacts` | Publisher contacts | `users` with role `publisherContact` |

   Also ask: **do publishers want to be notified when the review is published?** If yes, every work needs a publisher contact. Find the person who handled review copies or offers for each publisher in the mails, list them under `users` with role `publisherContact`, and set `publisherContactEmail` and `notifyPublisher: true` on each of the publisher's works. Report the works whose publisher has no contact in the mails.
4. **Mode B: have each case read.** Define the cases with `extract.js list`, using the user's folder meanings (step 3), and show the list to the user. Then run `extract.js run` with the same specs (see *Delegated reading* below). Every request is one case and nothing else. With a second model you can run the same specs again and compare the two per case: where they disagree, ask the user. Skip the rest of this step and go on with step 5.

   **Mode A: read each case**: `show <work> <folder>` (one folder per case) or `show <work> <thread>`. Use `threads --folder X`, `grep <work> REGEX` and `people <work>` to find things. Thread grouping uses Message-ID references, plus the same subject with a shared correspondent. One thread can cover several books, and one book can span several threads.
   If a reviewer agreed to review several books in one text (one thread or folder about several books, one deadline), that is a **review essay**: one review of several works. Ask the user if unsure.
5. **Write `<work>/cases.json`**. In mode B, start with the mechanical merge of the results. Pass the user's folder meanings, look up missing ISBNs by title, then review the draft with the user and copy it to `<work>/cases.json`:

   ```sh
   node scripts/merge-extract.js <work>/extract/<model> --submitted "4 Submitted" [--skip "<case>"] --title-lookup
   ```

   The merge applies these rules; check every case against them:
   - **Statuses:** `review.status` `accepted` and `submitted` are imported as such. `proposed`, `invited`, `bid` and `offered` become a work without a review process, unless the user wants bidding imported. `published`, `declined` and `withdrawn` are left out.
   - **Earlier reviewers:** `formerReviewers` are finished; don't import them.
   - **Identifiers:** prefer a valid ISBN; otherwise look the title up (Crossref title search, public data) or ask the user. Merge the same book across cases.
   - **Essays:** a review essay needs at least two works; otherwise treat it as a single review and ask.
   - **Publisher contacts** come from `publisherContacts`.
   - **What to ask the user:** every `questions` entry, every `issues` entry, and missing due dates.

   In both modes the format is the import format (skill `book-review-manager-api`, section 4), with tokens for people and bookkeeping fields starting with `_`:

   ```json
   {
     "users": [{ "person": "P0031", "roles": ["publisherContact"], "_evidence": "m0412" }],
     "objects": [{
       "isbn": "9783658449056", "publisherContactEmail": "P0031", "returnRequired": false,
       "_thread": ["t0187"], "_confidence": "high",
       "assignments": [{
         "email": "P0007", "status": "accepted", "dateCreated": "2025-01-14", "dueDate": "2025-07-31",
         "copyPreference": "physical", "copyStatus": "received", "copySentBy": "publisher",
         "_evidence": "m0533 (deadline agreed), m0561 (book arrived)"
       }]
     }]
   }
   ```

   Dates come from the mails: the agreement date becomes `dateCreated`, the agreed deadline `dueDate`, and the date of the last copy event `copyStatusDate`. If the ISBN or DOI is missing, ask the user, or check candidates with `ofr lookup`. Mark guesses with `_confidence: "low"` and a `_note`, and list them for the user. `"_skip": true` keeps a record in the file but out of the import.

   For a review essay, write one object per book **without** `assignments`, and put the essay with its reviewer in `essays`. In `copies`, name each book as in `works`, and give how the reviewer gets it. Each book has its own publisher and copy, so `copies` takes no `copySentBy` or `returnTarget`. `shippingAddress` goes once on the assignment, if a printed copy hasn't been sent yet.

   ```json
   "essays": [{
     "title": "New books on legal pluralism", "works": ["9783658449056", "9781509955107"], "_thread": ["t0042"],
     "assignments": [{
       "email": "P0007", "status": "accepted", "dateCreated": "2025-01-14", "dueDate": "2025-07-31",
       "copies": [{ "work": "9783658449056", "copyPreference": "physical", "copyStatus": "received" },
                  { "work": "9781509955107", "copyPreference": "electronic", "copyStatus": "sent" }]
     }]
   }]
   ```
6. **Complete the people locally** (user). `people <work>` shows which tokens lack `givenName`, `familyName` or `mailingAddress`. The user edits `private.json`. Publisher contacts need a mailing address if `returnRequired` is true. A physical copy that hasn't been sent needs `"shippingAddress": "@P0007"`.
7. **Convert**: `node scripts/cases-to-import.js <work>/cases.json --work <work> --out <work>/import.json`. Fix all errors. Read the warnings with the user, especially past or near due dates and contract deadlines, because the daily task sends reminders right after the import.
8. **Import, only when the user says so**, with the API skill's client:

   ```sh
   node api/bin/ofr.js import <work>/import.json --dry-run 2>&1 | node <skill>/scripts/eml.js mask <work>
   node api/bin/ofr.js import <work>/import.json          2>&1 | node <skill>/scripts/eml.js mask <work>
   ```

   The import creates the works first, then combines each essay's works (`ofr bundle create` under the hood) and imports the reviewer on the essay. A re-run finds the essay, because all its works are in it, and skips it. In the dry run, an essay's works that the same file would create have no id yet (`works: [null, ...]`).

## Delegated reading (`scripts/extract.js`)

```sh
export OFR_LLM_BASE_URL=<base URL> OFR_LLM_KEY_FILE=<key file>     # see step 0
node scripts/extract.js check --model <name>
node scripts/extract.js list <work> each-folder:"3 Assigned" each-thread:"1 Proposals" --exclude _Declined
node scripts/extract.js run  <work> <same specs> --model <name> --editor P0001,P0008
```

| Case spec | One case is |
| --- | --- |
| `folder:<end of folder>` | all messages filed in that folder |
| `thread:t0145+t0160` | these threads |
| `each-folder:<stage>` | each subfolder of the stage (threads filed directly in the stage are cases too) |
| `each-thread:<folder>` | each thread with a message in the folder or its subfolders |

`--editor` names the journal's own tokens (all the editor's addresses), so the model doesn't take the editor for a reviewer.

**What the script does:** it sends one case per request (masked, quoted replies cut, `--max-message` characters per message, default 3000) with a fixed JSON schema:
- **works:** title, ISBN, DOI, URL, publisher;
- **publisher contacts;**
- **review:** the current reviewer, status, agreement date, due date with its basis, copies per work;
- **earlier reviewers, questions** and **confidence**;
- **evidence:** message IDs for every value.

**What comes back:** results go to `<work>/extract/<model>/<case>.json`:
- `result` holds the answer;
- `issues` lists validation problems: not a bare token, unknown person, invalid ISBN, a date not in YYYY-MM-DD, evidence from outside the case;
- `removed` lists values deleted because they contained private data.

A second run skips finished cases; failed ones are tried again (`--force` redoes all). Rate limits (HTTP 429) and server errors are retried. Reasoning models may need `--max-tokens 32768`, otherwise their reasoning can use up the answer. The model is told not to repeat personal circumstances (health, family, moves) in any field.

`merge-extract.js <extract dir>` builds a draft cases file (`<extract dir>/cases.json`) from the results with fixed rules (step 5). Things it can't decide go into `_unresolved`: works without an identifier, and a work reviewed on its own and in an essay. Left-out cases go into `_skipped`. `--title-lookup` sends only book titles to Crossref and caches the answers.

## Quick reference (`scripts/eml.js`)

| Command | |
| --- | --- |
| `index <mail dir> --out <work> --ner [--ner-threshold 0.5]` | Parse, deduplicate (same Message-ID in several folders), pseudonymize (headers + local NER), thread |
| `add-name <work> <name>...` | Mask names NER missed (abbreviations, inflected forms); then `index … --reuse-ner` |
| `index <mail dir> --out <work> --reuse-ner` | Re-mask with the stored NER results, without running the model again (after `add-name` or a code update) |
| `folders <work>` | Folder tree with message counts |
| `threads <work> [--folder X] [--match RE] [--json]` | One line per thread: dates, count, folders, subject, people, ISBNs/DOIs |
| `show <work> <t…\|m…\|folder>… [--full] [--max N]` | Masked mail text; quoted replies cut unless `--full` |
| `grep <work> RE [--folder X]` | Search the masked full text |
| `people <work>` | Tokens, domains, activity, which profile fields are known |
| `mask <work>` | Mask stdin |

Tests: `npm test` in this folder (synthetic mails; the NER test is skipped if the model or `ner/node_modules` is missing).

## Common mistakes

- **Opening a mail file "just to check"** sends real data to the model. Use `show --full`.
- **Importing finished or abandoned processes.** The importer can't create `declined` or `cancelled`. Leave them out.
- **`accepted` without `dueDate`**: rejected. If no deadline was agreed, ask the user; don't invent one.
- **One review of several books imported as separate reviews.** Make it a review essay: the books in `objects` without assignments, the reviewer in `essays` with `copies`. The converter refuses an essay whose work has assignments of its own (the server would answer `409 member_of_review_essay`).
- **The same book in two cases**: merge them into one object. The converter flags duplicate identifiers.
- **A book chapter's DOI taken for the book's**: prefer the ISBN, or ask.
- **Re-indexing into a new work dir** creates new tokens, so `cases.json` no longer matches. Re-index into the same work dir.
