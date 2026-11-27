# Help center (`/help`)

Modelled on Yardline's help center. 59 articles in 10 topics: Getting started, Clients, Programs and workouts, Training in the gym, Inbox and messages, Nutrition, Gyms and teams, Switching to Spotter, Your account, For clients. Written from how each feature works in the code.

## How it's built

- Articles are Markdown in `resources/help/{topic}/{slug}.md` with front matter (`title`, `summary`, `order`, `popular`, `related`). Blockquotes starting **Tip**, **Note** or **Heads up** become callouts.
- Classes: `App\Help\HelpCenter`, `HelpArticle`, `HelpSearchIndex`; controller `HelpController`; Blade views in `resources/views/help`.
- Search ported from Yardline: stems, typos, prefix-as-you-type, coaching synonyms, section snippets with highlighted words, suggestions as you type (`/help/suggest`, throttled 120 a minute). A synonym's weight is capped at the typed word's. Add synonyms when a feature brings new wording (archive, habit, injury, progress, reschedule and swap were added on 2026-11-04).
- Linked from the sidebar (clients go to the For clients section), home footer, login page ("Trouble signing in?"), Train, setup step 2 and the progression rules box. Support card shows when `SUPPORT_EMAIL` is set.

## Rules

- When adding a feature, add or update its article. Client-facing features also get an article under `for-clients/`.
- Deliberately no articles on plans/pricing (billing isn't built) or the demo (404s where it's off). Help content is English only.

## Tests

`tests/Feature/HelpCenterTest.php` and `tests/Unit/HelpSearchIndexTest.php` check every page renders, every link and related article exists, and that real coach queries find the right article. Add a query test when an article answers a new question.
