# Agents screen — design

Approved by Luke, 2026-11-20.

## Why

The agent's work (#689) lands on its builder's My tasks list, tagged "Agent's
work". Luke does not want it there. It gets its own screen instead.

## What changes

- **My tasks** lists no agent work. The day planner is removed — no longer
  needed. Today's diary shows meetings only; chores and reminders already sit
  in the list below it.
- **Agents**, a new screen under Daily standup. Same tabs as My tasks (Today,
  Tomorrow, Next seven days, Further out, No date, Everything), no day planner.
  It lists every task, studio-wide, where it is the agent's turn. Each row
  carries an "Agent" tag and a "Looked after by" column (the deliverer), which
  can be filtered.
- The sidebar shows a "for today" count on Agents, like My tasks.
- The rules page, changelog and version are updated.

## Approaches

- **Separate screen sharing My tasks' sorting rules (picked).** The two lists
  now differ: no diary, no planner, a "looked after by" column instead of
  "your role", no hours.
- One screen with an agent mode — rejected: more branches inside one component
  for two lists that have stopped looking alike.

## Calls made by Claude

- Hours column left off the Agents screen: the agent spends them, not a person.
- The Agents screen is not added to the phone tab bar.
