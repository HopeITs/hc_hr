---
name: phase0
description: Implement one track (1-7) of the 19/11 meeting stories (leave alternative, profile direct edit, birthday notifications, probation review, permissions library, critical positions, volunteer onboarding journey) from docs/meeting-stories-audit-and-prompts-ar.md. Use when the user says "/phase0 3" or asks to implement a meeting-stories track.
---

# phase0

Argument: a track number 1-7 (`/phase0 4`). If missing or invalid, list the seven tracks (name + branch from the doc's table in section 3) and ask which one.

## Steps

1. **Read the source.** Read `docs/meeting-stories-audit-and-prompts-ar.md`. If the file is not in the working tree, run `git show origin/feature/attendance-purge-uploaded:docs/meeting-stories-audit-and-prompts-ar.md`. Extract:
   - the shared-context block (under "عنصر مشترك"),
   - the chosen track's prompt (`### Prompt N`),
   - the track's branch from the table in section 3,
   - the "القرارات المعتمدة" table and the open questions.
   Treat the prompt as the task spec; replace its `[الصق السياق المشترك]` line with the shared block.

2. **Branch.** Check `git status`. If there are tracked changes, stop and tell the user. Otherwise `git fetch origin` and create the track's branch from `origin/main` (`git checkout -b <branch> origin/main`). If the branch already exists, switch to it instead of recreating. Never work on `main` or on another track's branch.

3. **Read CLAUDE.md and the existing code the prompt names** before changing anything. Use the repo's skills when relevant (`livewire-development`, `fluxui-development`, `laravel-best-practices`).

4. **Plan first for tracks 4, 5, 6 and 7** (and any track where the prompt's open questions affect the design): show a short plan and the assumptions you will use for unresolved questions, then wait for the user's approval. For tracks 1-3 proceed directly.

5. **Implement** exactly the scope in the prompt. Do not touch files outside it. Never edit an existing migration; new migrations get a fresh timestamp later than the latest one in `database/migrations`. Add EN and AR translation keys together. Register new notifications in `app/Enums/NotificationCategory.php` by adding only your own lines (that file is shared with the other tracks).

6. **Test.** Write Feature tests for each new behaviour, then run `php artisan test --filter=<RelatedTests>`, then `composer test`, then `./vendor/bin/pint`. If a check fails, fix the cause; do not skip or weaken tests. Verify UI changes in the preview where the change is observable.

7. **Report.** State what was built, which tests and checks ran with their results, what was not verified, and any assumption taken for an open question. Do not commit or push unless the user asks.

## Rules

- One track per run. Another track means a new run (a new session is better, so several people or sessions can work in parallel).
- Security-critical scoping (roles, city scope, salary visibility) is never loosened to make a test pass.
- If the prompt conflicts with what the code actually does, stop and ask instead of guessing.
