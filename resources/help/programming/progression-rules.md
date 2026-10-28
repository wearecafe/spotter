---
title: Progression rules
summary: How Add load, Add reps, Hold and No rule decide next week's numbers from what the client actually did.
order: 3
popular: true
related: programming/creating-a-program, programming/how-programs-work, programming/program-calendar
---

Every exercise in a program has a rule under **Each week**. Each week, the rule looks at the same day's session the week before and decides the next numbers.

## The rules

| Rule | What it does |
| --- | --- |
| **Add load** | Adds the step to the load, e.g. 2.5 kg or 5%. |
| **Add reps** | Adds reps. A range climbs from the bottom: 8-10, then 9-10, then 10. A single number goes up: 5, then 6. |
| **Hold** | Keeps the numbers the same every week. |
| **No rule** | Repeats the row unchanged. |

## When a step is earned

The client earns the step when, in the previous session:

- every set was ticked done,
- reps were at least the target (the bottom of a range),
- the load was at least the prescribed load, and
- RPE was at or under the target, if you set one.

A set ticked done with reps or load left blank counts as done as prescribed, since the prescription is what the client sees in the box.

If any of that isn't true, the row **holds** at the same numbers, and the program shows why (for example "Held: RPE was above target").

## Steps

- Leave **Step** blank for the default: **2.5 kg** for loads in kilograms, **5 lb** for pounds, **1 rep** for reps.
- Or type your own: 5 kg, 10 lb, 2, or a percentage like 5%.

## Weeks that haven't happened yet

Future weeks show the plan if everything goes to plan. When the client finishes a session, Spotter re-plans from what they actually did.

## Misses, skips and deloads

- **Missed session**: the next week holds.
- **Skipped week** (you skipped it, see [Changing a single session](/help/programming/editing-one-session)): doesn't count as a step.
- **Starting mid-week**: weeks before the start don't add a step.
- **A deload you set by hand** on one session: once it's done, the plan picks up at the planned load again instead of stepping from the lighter week.

> **Tip** Range done? When a rep range reaches the top (8-10 becomes 10), Add reps stops and suggests adding load instead. Switch the rule to **Add load** and set the reps back to the bottom of the range.
