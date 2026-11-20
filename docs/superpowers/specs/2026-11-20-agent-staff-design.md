# Agent as a member of staff — design

2026-11-20. Small change.

## Why

When Luke's AI agent does the build on a task, today he has to put himself in
the seat, and the build hours fill his schedule. In reality he and his staff
only design and review. The agent's hours should sit on the agent, not on him.

## What it is

- An **Agent** person who appears on the staff list like anyone else. Hours
  can be changed on the usual screen.
- It starts at **12 hours a day, Monday to Friday**, with no weekend hours.
- It can be put in any seat on a task. Its hours count on its own row in
  capacity, never on anyone else's.
- It has no login. It can't move a task, approve anything, or receive
  notifications.

## Who owns the task while the agent has it

Whenever it is the agent's turn on a task, the task is the **deliverer's**
turn instead. That covers their task list, the standup's "waiting on",
notifications, and moving the task on. The reviewer is usually the client,
so the reviewer can't own it.

So a task with the agent in any seat needs a real person as deliverer. Saving
one without a deliverer is refused with a plain message.

## Approaches

1. **A built-in person, marked as an agent (picked).** Capacity, pickers,
   avatars and the calendar already handle people, so the only new behaviour
   is the handover to the deliverer and the deliverer rule.
2. A special marker, like "the client" in the reviewer seat. Rejected: every
   screen that lists people would need extra code to show it, and Luke wants
   it on the staff list with editable hours.

## Left to the builder

- How a person is marked as an agent (a column or a flag), and how the one
  agent is created on existing sites (an upgrade step).
- The agent's email: it needs a placeholder that can never receive mail.
- Agent can't be made the deliverer.
- The rules page (`src/rules.ts`) gets a line on the agent and the handover.
