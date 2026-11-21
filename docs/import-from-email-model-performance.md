# Importing from email: model performance

How well two open models read book review correspondence in **mode B** of the email import skill
([`skills/book-review-manager-email-import`](../skills/book-review-manager-email-import/SKILL.md)), measured on
one journal's real mail archive in November 2026.

**In short:** both models read the cases reliably and agreed with each other on nearly every reviewer and status.
Neither got due dates, agreement dates or publisher contacts right often enough to import them without
review. `gemma-4-31b-it` did as well as `deepseek-v4.1-flash-1017` with about a tenth of the output tokens.

## Setup

- **Data:** 31 cases from a German-language mailbox of a book review editor (offers, requests, assigned reviews), masked with
  `eml.js index --ner`. The models only saw tokens such as `P0044`, never names or addresses.
- **Run:** `extract.js run`, one request per case, temperature 0, JSON output, the same prompt for both models.
  Then `merge-extract.js --title-lookup` built a draft cases file from each model's results.
- **Reference:** the cases file that was reviewed with the editor and actually imported (25 works, 2 review essays,
  9 reviews). It was built earlier and separately, by reading the masked mails directly (mode A).
- **Models:**

  | Model | Type |
  | --- | --- |
  | `gemma-4-31b-it` | Instruct model, no reasoning |
  | `deepseek-v4.1-flash-1017` | Reasoning model |

## Reliability and cost

| | gemma-4-31b-it | deepseek-v4.1-flash-1017 |
| --- | --- | --- |
| Cases answered | 31 / 31 | 31 / 31 |
| Cases with schema issues | 1 (an "essay" with only one work) | 0 |
| Answers with private data removed by the scrubber | 2 (both in `questions`) | 2 (both in `questions`) |
| Prompt tokens | 99,433 | 91,033 |
| Completion tokens | 12,885 | 148,951 (of which 138,604 reasoning) |
| Largest single answer | 905 tokens | 17,030 tokens |

- Neither model failed or returned broken JSON.
- The reasoning model's largest answer is over the default `--max-tokens 8192`. Run reasoning models with a higher limit,
  e.g. `--max-tokens 32768`, or long cases fail.
- In both runs, two of the model's questions to the editor repeated a masked value from the mails. The scrubber
  caught both before they reached the orchestrator. Keep the scrubber on.

## How much the two models agree

The same 31 cases, compared field by field on the models' raw answers:

| Field | Agreement |
| --- | --- |
| Current reviewer | 30 / 31 (97%) |
| Review status | 30 / 31 (97%) |
| Due date | 31 / 31 (100%), but only 4 cases had a date at all |
| Agreement date | 28 / 31 (90%) |
| Kind of case (single review, essay, offer, none) | 27 / 31 (87%) |
| ISBNs found | 29 / 31 (94%) |
| Number of works | 25 / 31 (81%) |

Where the two disagree is a cheap, useful signal: those are the cases to show the editor.

## Compared with the imported reference

After the merge, compared with the reference:

| | gemma-4-31b-it | deepseek-v4.1-flash-1017 |
| --- | --- | --- |
| Reference works found | 20 / 25 | 20 / 25 |
| Reference reviews found | 7 / 9 | 6 / 9 |
| … with the same reviewer | 7 / 7 | 6 / 6 |
| … with the same status | 7 / 7 | 6 / 6 |
| … with the same copy preference | 6 / 7 | 5 / 6 |
| … with the same agreement date | 4 / 7 | 3 / 6 |
| … with a due date within 31 days | 3 / 6 | 2 / 5 |
| Review essays with exactly the same works | 1 / 2 | 1 / 2 |
| Same publisher contact | 5 / 16 | 6 / 16 |
| Reviews invented where the reference has none | 0 | 0 |

How to read this:

- **Works:** both models missed the same 5 reference works, and both found the same 14 works that aren't in the
  reference. That points to a difference in scope between the two processes (cases defined differently, offers the
  editor decided not to import), not to reading errors. The comparison can't tell these apart without looking at the
  data.
- **Reviewers and statuses** were right whenever a model found the review. No model made up a review.
- **Due dates** are the weak point. The prompt asks for a best guess from vague wording ("late summer", "for the
  next issue"). The reference did that, but the models left the date empty in most cases. The merge then put in a
  placeholder (marked `_confidence: low`). Every due date needs checking by the editor.
- **Agreement dates and publisher contacts** matched only about half and a third of the time. Treat them as
  suggestions.

## Other differences

| | gemma-4-31b-it | deepseek-v4.1-flash-1017 |
| --- | --- | --- |
| Self-reported confidence | high 29, medium 2 | high 12, medium 17, low 2 |
| Questions for the editor | 32 | 89 |
| Publisher contacts named | 23 | 14 |
| Works with an ISBN | 31 of 55 | 19 of 48 |

- `gemma-4-31b-it` calls almost everything "high" confidence. Its rating says little.
  `deepseek-v4.1-flash-1017` spreads its ratings and asks about three times as many questions. That costs the editor
  more time but marks the doubtful cases better.
- `gemma-4-31b-it` named more ISBNs and publisher contacts. That didn't make it more accurate against the reference.

## Recommendation

- Either model is good enough for mode B. Use **`gemma-4-31b-it`** for the main run: about as accurate as the other,
  much faster and cheaper.
- If budget allows, run **`deepseek-v4.1-flash-1017`** on the same cases as a second opinion and show the editor
  the cases where the two disagree.
- Whatever the model, have the editor check due dates, agreement dates, publisher contacts and review essays
  before the import.

## Limits of this test

- One journal, 31 cases, German-language mail. The numbers are indicative, not a benchmark.
- The reference came from a separate process with somewhat different case boundaries. That affects mainly the
  numbers for works found.
- Speed wasn't measured. The runs overlapped and timings weren't recorded per case. The completion token counts above
  are the best indication of the cost difference.
