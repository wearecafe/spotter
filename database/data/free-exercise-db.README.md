# free-exercise-db

`free-exercise-db.json` is `dist/exercises.json` from https://github.com/yuhonas/free-exercise-db
at commit `f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5` (2026-09-27): 876 exercises with
instructions, muscles, equipment, level and start/end photos.

Licence: the Unlicense (public domain). Commercial use is allowed and attribution isn't required;
we credit the project anyway. The full licence text is in `free-exercise-db.LICENSE.md`.

Images are not copied into this repo. By default they're served from GitHub at the same pinned
commit (see `EXERCISE_IMAGE_BASE_URL` in `.env.example`). To serve them from our own storage or
a CDN, copy the `exercises/` folder from that commit and point `EXERCISE_IMAGE_BASE_URL` at it.

Load or refresh: `php artisan db:seed --class=FreeExerciseDbSeeder --force` (also run by
`ExerciseLibrarySeeder`, which the deploy command calls). Safe to run repeatedly.
