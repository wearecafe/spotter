# Hosting, environment, queue and scheduler

Laravel Cloud.

## Environment variables

- `ANTHROPIC_API_KEY` (optionally `ANTHROPIC_MODEL`; default is claude-sonnet-5-5)
- `APP_TIMEZONE=America/Toronto`
- `MEDIA_DISK`: an object storage bucket
- `SUPPORT_EMAIL` (also shows "Email support" in the help center)
- Email: `MAIL_MAILER=resend`, `RESEND_API_KEY` (or `RESEND_KEY`), and `MAIL_FROM_ADDRESS` on a domain verified in Resend
- `SPOTTER_DEMO=true` to turn on the demo gym
- Optional: `EXERCISE_IMAGE_BASE_URL`

## Queue worker

AI drafts, program-file reading, invite emails, team invites and unread-message emails all run on the queue.

## Scheduler (`routes/console.php`)

- `clients:auto-archive` daily at 05:30
- `inbox:scan-missed` daily at 06:00
- `messages:notify-unread` every five minutes
- `spotter:demo:reset --force` at 03:00 when the demo is on

## Deploy

- Deploy command: `php artisan migrate --force` is enough; it also loads the exercise library (migration `2026_10_30_000001_load_exercise_library` runs the idempotent `ExerciseLibrarySeeder`). To refresh the library by hand: `php artisan db:seed --class=ExerciseLibrarySeeder --force`.
- After deploying: `php artisan spotter:staff <email>` for staff access (`--revoke` to remove); `php artisan spotter:mail-test <email>` to check email; optionally `php artisan spotter:demo:reset --force` to build the demo with AI-drafted inbox right away.

## Exercise library

876 exercises from free-exercise-db (Unlicense, pinned commit `f00c92c`, `database/data/`) with photos, steps, muscles, equipment and level. Photos come from GitHub by default; set `EXERCISE_IMAGE_BASE_URL` to self-host.
