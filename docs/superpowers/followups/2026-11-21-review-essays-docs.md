# Follow-up: documentation for review essays (apply after merging)

> **Applied on 2026-11-21**, with one change: review essays became part of the import document (`essays`, imported by `importData` / `ofr import` after the works). So step 9 of section 3.3 and the manual `essay-<n>.json` were dropped from the email-import skill; `cases-to-import.js` resolves and checks `essays` like the rest.

These texts are not in the branch `worktree-review-essays`, so that they don't conflict with the uncommitted move of `api/SKILL.md` to `skills/objects-for-review-api/SKILL.md` and with the uncommitted `skills/objects-for-review-email-import/SKILL.md` in the main checkout. Apply them after the merge.

## 1. `README.md`

Under "Changed or new in this fork", after the bullet **Inviting reviewers** (before **Reservation management**), add:

```markdown
- **Review essays.** Several works can be offered as one review essay, created upfront or by combining works already offered (taking over a reviewer one of them already has). The process with the reviewer (offer, invitations, agreement, due date, submission, publication) runs once for the essay; the review copy and the publisher notification stay with each work. See [the manual](docs/workflow.md#9-review-essays-one-review-of-several-works).
```

## 2. `skills/objects-for-review-api/SKILL.md` (formerly `api/SKILL.md`)

### 2.1 Section "3. Commands"

Add at the end of the code block, before `ofr import ...`:

```sh
ofr bundle create --title "New books on legal pluralism" --works 12,13,14 [--description <text>] [--hidden] \
    [--mode direct|bidding] [--editor <email>]  # combine works into a review essay; prints the essay
ofr bundle add 20 15 [--copy have|physical|electronic|other]   # add work 15 to essay 20 (--copy: the reviewer's access, if the essay has one)
ofr bundle remove 20 15                  # take work 15 out of essay 20; it is offered on its own again, hidden
```

Then add this paragraph after the paragraph on `add-book` options:

```markdown
**Review essays** (one review of several works). The essay is an object of its own (`isReviewEssay: true`) with its works in `members`; each work has `bundleId`. The essay holds the offer, the bids, invitations and the reviewer. Each work keeps its publisher contact and copy settings, and has its own copy record (a *mirror* assignment, with `bundleAssignmentId`) for the review copy steps and the publisher notification. `bundle create` takes the offer settings not given from the first work. If one of the works already has an accepted reviewer, that reviewer becomes the essay's reviewer (no email is sent through the API); give their access to the other works with `copyPreferences` in the HTTP call (`{"<objectId>": "physical", ...}`; `ofr bundle create` has no option for it, the default is `have`). A work in an essay can't get an assignment of its own: import the reviewer on the essay (see `copies` in section 4).
```

### 2.2 Section "4. Import format", subsection "assignments"

Add after the table "Review copy (...)":

```markdown
Review essays: import the reviewer on the essay (`ofr add-assignment <essayId> ...`), not on its works. Instead of `copyPreference`, `copyStatus` and `copyStatusDate`, give one entry per work in `copies`:

| Field | |
| --- | --- |
| `copies` | `[{ "objectId": 12, "copyPreference": "physical", "copyStatus": "received", "copyStatusDate": "2025-02-03" }, ...]`. Only works of the essay, each at most once; works not named get `have`. `copyPreference` and `copyStatus` take the values above |
| `shippingAddress`, `phone` | Once for the essay. Required if a work's printed copy is still `pending` or `requested` |
| `dueDate`, `status`, `dateCreated`, `dateStatus`, `copyNote`, `submissionId` | As for a single work, once for the essay |

The works get the reviewer's status from the essay, and each work's copy record gets its copy status. Review essays are not part of the `import` document: import the works, create the essay with `ofr bundle create`, then import the reviewer with `add-assignment` on the essay.
```

### 2.3 Section "5. Use from a Node script"

Add to the list of methods: `createBundle(data)`, `addBundleMember(bundleId, objectId, copyPreference)`, `removeBundleMember(bundleId, objectId)`.

### 2.4 Section "6. HTTP API reference"

Add these rows to the table, after `GET objects/{id}/assignments · POST objects/{id}/assignments`:

```markdown
| `POST bundles` | Create a review essay: `{title, description?, objectIds[], copyPreferences?, assignmentMode?, bidDeadline?, responsibleEditorId? / responsibleEditorEmail?, hidden?}`. Returns the essay with `members` (201) |
| `POST bundles/{id}/members` | Add a work: `{objectId, copyPreference?}`. Returns the essay |
| `DELETE bundles/{id}/members/{objectId}` | Remove a work (offered on its own again, hidden). Returns the essay |
```

Add after the table, before "Errors are ...":

```markdown
`GET objects/{id}` includes `isReviewEssay` and `bundleId`, and for an essay `members[]` (each with its copy record in `assignments`). `POST objects/{id}/assignments` on an essay accepts `copies` (section 4).
```

Replace the paragraph "Errors are ..." with:

```markdown
Errors are `{"error": "<code>", "errorMessage": "<text>"}` with status 400 (invalid input), 403 (not allowed), 404, 409 (duplicate: `duplicateIdentifier` with `objectId`, `duplicateAssignment` with `assignmentId`, `reviewerExists`, `offerExists`, `submissionExists`) or 502 (lookup service failed; retry later).

Review essays add:

- 400 `titleRequired`, `worksRequired`, `invalidCopies` (`copies` or `copyPreferences` name a work outside the essay, or one twice; or `copies` is given for an assignment of a work that is not a review essay), `invalidCopyPreference`, `invalidCopyStatus`, `shippingAddressRequired`, `invalidResourceType` (`PUT objects/{id}` with `resourceType: reviewEssay`: an essay is created with `POST bundles`);
- 404 `bundleNotFound` (no review essay with this id), `objectNotFound`;
- 409 `cannot_combine` (`POST bundles`) and `review_essay_rule` (adding or removing a work, or changing the `resourceType` of an essay), each with `reason`, one of:
  - `plugins.generic.objectsForReview.reviewEssay.error.notAvailable` (a work is already in an essay, attached to a submission, closed, or an essay itself; or the id is unknown),
  - `...error.twoReviewers` (more than one work has a reviewer, or the work to add has one),
  - `...error.pending` (a contract offer or an invitation round is pending for a work),
  - `...error.lastMember` (the last work can't be removed),
  - `...error.submitted` (the essay is submitted),
  - `...error.resourceType` (`PUT objects/{id}` would change the resource type of an essay);
- 409 `member_of_review_essay` with `bundleId`: an assignment was imported on a work of an essay; import it on the essay.
```

## 3. `skills/objects-for-review-email-import/SKILL.md` (main checkout only)

### 3.1 Workflow, step 4 ("Read each case")

Append to the step:

```markdown
   If a reviewer agreed to review several books in one text (one thread or folder about several books, one deadline), that is a **review essay**: one review of several works. Ask the user if unsure.
```

### 3.2 Workflow, step 5 ("Write `<work>/cases.json`")

Append to the step:

```markdown
   For a review essay, write one object per book **without** `assignments`, and add an `_essay` bookkeeping entry for the user, e.g. `"_essay": { "title": "New books on legal pluralism", "works": ["9783658449056", "9781509955107"], "reviewer": "P0007", "dueDate": "2025-07-31" }`. Note per book how the reviewer gets it (`copyPreference`, `copyStatus`; `copies` takes no `copySentBy` or `returnTarget`), since each book has its own publisher and copy.
```

### 3.3 Workflow, after step 8 ("Import")

Add a step:

````markdown
9. **Review essays, after the import, only when the user says so.** For each `_essay`:
   1. Find the ids of the imported works (`ofr find <isbn>`).
   2. Combine them: `ofr bundle create --title "<title>" --works <id>,<id>`. The output is the essay; note its `id`.
   3. Import the reviewer on the essay, not on the works, with one `copies` entry per work. Write the data to `<work>/essay-<n>.json` with the person token as `email`:

      ```json
      {"email": "P0007", "status": "accepted", "dateCreated": "2025-01-14", "dueDate": "2025-07-31",
       "copies": [{"objectId": 12, "copyPreference": "physical", "copyStatus": "received"},
                  {"objectId": 13, "copyPreference": "electronic", "copyStatus": "sent"}]}
      ```

      `cases-to-import.js` does not convert this file, so the user replaces the token with the real address (and a `shippingAddress`, if needed) locally; never put the real values into the session. Then:

      ```sh
      node api/bin/ofr.js add-assignment <essayId> --data @<work>/essay-<n>.json 2>&1 | node <skill>/scripts/eml.js mask <work>
      ```

      A printed copy that is still `pending` or `requested` needs `shippingAddress`, once for the essay. The reviewer's account is found by email; list them under `users` in the import so that it exists.
   An assignment imported on a work of an essay is refused with `409 member_of_review_essay`.
````

### 3.4 Common mistakes

Add:

```markdown
- **One review of several books imported as separate reviews.** Make it a review essay (step 9): works first, then `ofr bundle create`, then the reviewer on the essay with `copies`.
```
