# Demo data for the live scenario runs (hr_db)

`ScenarioDemoSeeder` adds what scenarios 1 (hiring), 4 (onboarding) and 13 (policies) need on a copy of the real data. It refuses to run in production.

```bash
mysqldump -uroot --single-transaction hr_db > storage/app/hr_db_before_demo_<date>.sql
MAIL_MAILER=array php artisan db:seed --class=ScenarioDemoSeeder
```

What it adds, each part only when it is not there yet (so running it again changes nothing):

| Part | How |
|---|---|
| Recruitment approval workflows | `RecruitmentWorkflowSeeder`: one active definition per request type (manager → finance → city HR; HR Syria for direct opening). |
| One campaign taking applications | On the newest free vacancy (open, no campaign in progress), public, open for a month. Made by the first holder of HR-SYR through the Campaigns services (create, post, form, link, launch). Their HR-SYR role is made active for this and the role they had is given back. The public link is printed; the post is titled "Demo campaign (scenario runs)". Skipped with a warning when no vacancy is free. |
| Two policies required for onboarding | `database/data/demo-policies/`: `code-of-conduct` (kind `policy`, to agree to) and `media-guideline` (kind `review`, only reviewed), uploaded into the Policies folder as HR's uploads are. They are text files, so they are read at once, with no Gemini key. |

To undo, restore the dump. Deleting the two files from the Policies folder removes the policies; a campaign is closed from its page, never deleted.

First run on hr_db (2026-11-26): campaign #148 on vacancy #145 (open until 2026-12-26), policies #1 and #2. Backup: `storage/app/hr_db_before_T10_2026-11-26.sql`.
