/**
 * Every rule Forge follows, in plain English (#467): what happens, and why.
 * Read by the Settings screen. A change to how Forge behaves changes this
 * file in the same pull request as its changelog entry.
 */

/** One rule Forge follows: what happens, and why. */
export interface Rule {
  what: string;
  why: string;
}

/** A group of rules, one heading on the Settings screen. */
export interface RuleSection {
  id: string;
  title: string;
  rules: Rule[];
}

export const RULES: RuleSection[] = [
  {
    id: 'journey',
    title: 'The task journey',
    rules: [
      {
        what: 'Every task starts as a Future Idea and moves forward one stage at a time: Future Idea, Triage, Bug Tracking (bugs only), Documentation Period, Technical Audit, Design Process, Up Next, In Development, In Review, Completed, Released.',
        why: 'No stage can be jumped, because jumping one would skip the checks that stage is there for.',
      },
      {
        what: 'To leave Future Idea a task needs a description, where it came from, someone doing the work, a different person reviewing it, and its client confirmed.',
        why: 'The reviewer\'s approvals later on need somebody to belong to, and work added under the wrong client is caught before anyone plans it.',
      },
      {
        what: 'To leave Triage a task needs its type, a priority, a duplicate check, the decision to go ahead and who pays; bugs then go to Bug Tracking and everything else to Documentation Period.',
        why: 'Triage is where we decide whether and how the work happens, before anyone spends time on it.',
      },
      {
        what: 'To leave Bug Tracking a bug needs its kind, what was expected against what happened, steps to reproduce it, where it was seen, how serious it is, a first diagnosis, who pays, and a comment with a link to evidence added during this stage.',
        why: 'A bug has to be understood and shown to be real before anyone plans a fix.',
      },
      {
        what: 'To leave Documentation Period a task needs its description, what it deliberately does not cover, when it counts as completed, which sites it affects, and the reviewer\'s documentation approval.',
        why: 'Everyone agrees what is being built, and what isn\'t, before the work is planned.',
      },
      {
        what: 'To leave Technical Audit the risks must be marked as listed and the reviewer must give technical approval.',
        why: 'It is the reviewer\'s sign-off that the documented approach is sound.',
      },
      {
        what: 'To leave Design Process a task with a designer needs its responsive states and its empty, loading, error and no-access states marked done, plus the reviewer\'s design approval; a task with no designer is asked for nothing here, and a design link is always optional.',
        why: 'Not every task has a design, so only tasks with a designer go through the design checks.',
      },
      {
        what: 'To start work (leave Up Next) a task needs the doer, reviewer and deliverer named, hours for each of them, a start and a due date, a priority, room in everyone\'s week, and enough of the client\'s support hours.',
        why: 'Work only starts once it is fully planned, staffed and paid for.',
      },
      {
        what: 'To go to In Review every line of the task\'s checklist must be ticked; a task with no checklist goes straight through, and \'How to test\' is optional.',
        why: 'The checklist is the doer\'s own list of what done means.',
      },
      {
        what: 'A task\'s checklist saves as soon as it changes, without Save changes; every other edit in the task panel still waits for Save changes.',
        why: 'A tick or a new line is too easy to lose by closing the task.',
      },
      {
        what: 'A task\'s dates must stay in order (start, then due, then review by, then release by), though any of them may be in the past.',
        why: 'Dates that run backwards make the plan and the timeline meaningless.',
      },
      {
        what: 'A task with work beneath it takes its progress and dates from that work, and can only reach Released once everything beneath it is Completed; cancelled, rejected and duplicate work beneath it doesn\'t count.',
        why: 'A parent can\'t claim to be finished while its parts aren\'t, and work that was deliberately stopped shouldn\'t hold it open forever.',
      },
      {
        what: 'When a move is refused nothing changes, and the task lists everything still missing at once.',
        why: 'Being told one missing thing at a time means fixing it, trying again and being refused for the next.',
      },
    ],
  },
  {
    id: 'triage',
    title: 'Triage',
    rules: [
      {
        what: 'A new idea\'s client has to be confirmed on purpose before it can go to Triage; work made from a client request, a recurring task or a reminder counts as confirmed already.',
        why: 'Picking a client when adding work isn\'t a check that it\'s the right one.',
      },
      {
        what: 'Who pays must be Client, Site bug or No charge before a task leaves Triage; \'To be confirmed\' doesn\'t count as an answer.',
        why: 'Nobody should plan work until it\'s settled whether the client is paying for it.',
      },
      {
        what: 'Only an administrator can set or change who pays.',
        why: 'It decides what a client is charged, so it isn\'t left to whoever can edit the task.',
      },
      {
        what: 'A Site bug is something we delivered that broke: it uses none of the client\'s hours but still goes through every stage and still takes our people\'s time.',
        why: 'We don\'t charge clients for fixing our own mistakes, but the fix still has to be done properly.',
      },
      {
        what: 'Apart from who pays, any staff member on the client can answer the triage questions; no separate triage approver is needed.',
        why: 'Triage never waits on one particular person being available.',
      },
      {
        what: 'Triage can end a task instead of going ahead: Rejected needs a reason, Duplicate needs the task it duplicates on the same client, and Deferred needs a reason and sends it back to Future Idea.',
        why: 'Every \'no\' or \'not now\' is kept with its reason, so it can be found when the idea comes up again.',
      },
      {
        what: 'Deferred can also be chosen from Up Next, and a deferred task stays open as an idea.',
        why: 'Planned work sometimes has to wait without being thrown away.',
      },
      {
        what: 'A task\'s client can be changed until work starts, unless it is linked to other work, came from the client\'s own request, has comments the client can see, or was made by a recurring task or reminder.',
        why: 'Once hours are being spent or the client is involved, the task belongs to that client for good.',
      },
      {
        what: 'When a task moves to another client, anyone who can\'t work on that client is taken off it, and any hours it was holding move with it.',
        why: 'Nobody should hold a task they can\'t open, and the hours should sit with the client the work is for.',
      },
    ],
  },
  {
    id: 'review',
    title: 'Review and release',
    rules: [
      {
        what: 'An administrator can tick the review checks for anyone, but only the named reviewer, or a stand-in an administrator has named, can move the task to Completed; an administrator who isn\'t the reviewer has to use an override.',
        why: 'A review only means something if it\'s done by the person chosen to do it.',
      },
      {
        what: 'To pass review the reviewer marks the review checklist done, confirms every line of the task\'s checklist, records any extra hours spent fixing things after review (0 for none) and gives review approval.',
        why: 'Sign-off is a set of deliberate checks, not a single click.',
      },
      {
        what: 'Every open question to the client must be answered before a review can pass, even when the client is the reviewer.',
        why: 'Work shouldn\'t be signed off while the client is still waiting for an answer.',
      },
      {
        what: 'Sending work back from review to In Development needs a reason and a note of what was wrong, and starts a fresh review in which the earlier review\'s ticks no longer count.',
        why: 'Each review judges the work as it now is, while the earlier attempt stays in the history.',
      },
      {
        what: 'Only the named deliverer, or their stand-in, can release a Completed task.',
        why: 'Releasing is a deliberate act by the person responsible for delivering the work.',
      },
      {
        what: 'To release, the delivery checklist must be marked done, release notes written, everything the task waits on must have reached Completed, and this round\'s review approval must still be on the task.',
        why: 'Nothing goes live ahead of what it depends on, or without a note of what changed.',
      },
      {
        what: 'Releasing doesn\'t ask how, where or when the work was released; the release time is recorded automatically.',
        why: 'Those questions added typing without telling anyone anything they used.',
      },
      {
        what: 'A client site\'s very first release is refused until its launch-critical onboarding steps, such as domain, hosting, email and legal, are done.',
        why: 'A site shouldn\'t go live before the basics it depends on are settled.',
      },
      {
        what: 'The client is emailed when their task reaches Completed, worded as approved and ready, and again when it is Released, which is the final confirmation.',
        why: 'Clients only hear that work is done once it is actually live.',
      },
    ],
  },
  {
    id: 'blocked',
    title: 'Blocked work',
    rules: [
      {
        what: 'Any task still in progress (not Released or ended) can be blocked, and the only thing you must say is who owns the blocker: one of our people or the client.',
        why: 'A block should be quick to record at the moment you find out about it.',
      },
      {
        what: 'What is blocking it, what it is waiting on and a target date are optional extras.',
        why: 'They help, but not knowing them yet shouldn\'t stop you recording the block.',
      },
      {
        what: 'Unblocking needs a note of how it was resolved, and the task goes back to exactly the stage it was blocked from, with no choice of stage.',
        why: 'Unblocking can\'t be used as a way round a stage\'s checks.',
      },
      {
        what: 'While a task is blocked it can\'t be moved on, sent back or ended; unblock it first, unless an administrator overrides.',
        why: 'A blocked task is paused where it was, so its place in the journey stays clear.',
      },
      {
        what: 'The time a task spends blocked is added up across every block and kept for reporting.',
        why: 'It shows how long work really waited.',
      },
      {
        what: 'A task blocked at Up Next or later keeps its people\'s time booked and its client hours held.',
        why: 'When it unblocks it still needs that time, so the week shouldn\'t look free.',
      },
      {
        what: 'Blocked tasks show on the daily standup for whoever\'s turn it was.',
        why: 'Stuck work needs chasing every day.',
      },
    ],
  },
  {
    id: 'back',
    title: 'Going back and reopening',
    rules: [
      {
        what: 'A task can be sent back to any earlier stage, including stages it skipped and including from Completed or Released, but always with a reason.',
        why: 'Work that goes backwards without a written reason leaves nobody able to explain it later.',
      },
      {
        what: 'Bug Tracking is only ever offered for bugs, going forwards or back.',
        why: 'Its checks only make sense for a bug.',
      },
      {
        what: 'Sending a task back keeps everything already answered at earlier stages; only the review starts again.',
        why: 'Going back to fix one thing shouldn\'t undo approvals that still stand.',
      },
      {
        what: 'A Completed or Released task can be reopened to Documentation Period or In Development with a reason, which starts a new round where every check is asked again.',
        why: 'Reopening never rewrites history: the finished round stays on record as it was.',
      },
      {
        what: 'A task that goes back before Up Next gives back any client hours it was holding, but hours already spent once work started stay spent.',
        why: 'Held hours follow the plan, but work that was done can\'t be undone.',
      },
      {
        what: 'A task can be cancelled, with a reason, from any stage except Blocked and Released, and cancelling before work starts gives back its held hours.',
        why: 'Stopping work is a decision worth recording, and the client shouldn\'t pay for work that never started.',
      },
      {
        what: 'Rejected, duplicate and cancelled tasks never move again; they and released tasks can be archived, which hides them from everyday views but keeps them in reports.',
        why: 'Ended work is part of the record, and reports need it.',
      },
      {
        what: 'An administrator can move a task to any stage with a reason, and the task is permanently marked as overridden.',
        why: 'There has to be a way out of a genuinely stuck task, and it should always be visible that one was used.',
      },
      {
        what: 'Even an override can\'t put a task that isn\'t a bug into Bug Tracking, move archived work, or let a client move work.',
        why: 'Some limits protect the record and the client boundary rather than the workflow.',
      },
    ],
  },
  {
    id: 'recurring',
    title: 'Recurring tasks',
    rules: [
      {
        what: 'Only administrators can create, change, pause or end recurring tasks.',
        why: 'They create work for people automatically, so they are treated as setup rather than everyday work.',
      },
      {
        what: 'A recurring task needs a title, what to do, a type, how often it repeats, a start date, a client, at least one person and the hours each person spends.',
        why: 'Every copy it makes has to be clear, belong to a client and count properly against people\'s time.',
      },
      {
        what: 'It can repeat every day, every weekday, on chosen days of the week, or on a day of the month, with an optional end date.',
        why: 'Those cover the routines we actually run without a complicated schedule to set up.',
      },
      {
        what: 'Each due day makes one dated copy per person, placed straight into Up Next without going through the earlier stages or their checks.',
        why: 'A chore is due because of the date, not because anyone planned it.',
      },
      {
        what: 'A recurring task has no checklist, reviewer or deliverer: each person\'s one tick finishes their own copy and sends it straight to Released.',
        why: 'A chore has nothing to review or deliver; doing it is the whole job.',
      },
      {
        what: 'Only the person a copy belongs to can tick it, though an administrator can tick for anyone.',
        why: 'Each person answers for their own part.',
      },
      {
        what: 'Each copy of a recurring task is made free, so it uses none of the client\'s support hours unless an administrator changes who pays on that copy.',
        why: 'They are our own routine work, not something a client asked for.',
      },
      {
        what: 'The hours each person spends count against their capacity for the days ahead, before the copies exist.',
        why: 'Otherwise next week would look emptier than it really is.',
      },
      {
        what: 'Days missed while nobody opened Forge are still made the next time anyone does, while a paused recurring task skips its days.',
        why: 'Missed chores stay visible instead of quietly disappearing.',
      },
      {
        what: 'A copy\'s client can\'t be changed on the copy; change it on the recurring task instead.',
        why: 'Otherwise the next copy would be made on the old client again.',
      },
    ],
  },
  {
    id: 'meetings',
    title: 'Meetings and the standup',
    rules: [
      {
        what: 'Standing meetings are set up and changed by administrators, and each one names a host.',
        why: 'The host is the person who confirms each meeting happened, so every meeting needs one.',
      },
      {
        what: 'A meeting repeats weekly, fortnightly, every four weeks or monthly on a date, with an optional end date, and a single meeting can be moved or cancelled without touching the rest.',
        why: 'One-off changes shouldn\'t break the standing arrangement.',
      },
      {
        what: 'A meeting\'s hours are its length rounded up to the next half hour; the host and each person attending carry those hours in their capacity, while the client is charged once.',
        why: 'Everyone in the room is busy for that time, but the client pays for one meeting.',
      },
      {
        what: 'Client hours are held for meetings in the next twelve weeks that fall inside the client\'s current term; later ones are only forecast.',
        why: 'The balance shows what is really coming without locking up a whole year of hours.',
      },
      {
        what: 'Only a meeting marked held uses the client\'s hours; a cancelled meeting or one nobody came to costs nothing, and a meeting left unsettled after its day gives its hours back.',
        why: 'Clients pay for meetings that happened, never for ones that didn\'t.',
      },
      {
        what: 'Only an administrator or the meeting\'s host can mark a meeting held, cancelled or \'nobody came\', and past meetings can still be settled later from the standup.',
        why: 'The person who was there is the one who knows what happened.',
      },
      {
        what: 'Changing a standing meeting\'s length or time updates its coming meetings; changing its day, how often it runs or bringing its end date forward replaces them and gives back their hours; meetings already moved or dealt with stay as they are, and a rename changes nothing else.',
        why: 'Coming meetings should match the arrangement without undoing changes somebody made by hand.',
      },
      {
        what: 'The daily standup is worked out fresh from what is true right now, and nothing is flagged or cleared by hand.',
        why: 'An item leaves the standup the moment its problem is fixed, and can\'t be hidden without fixing it.',
      },
      {
        what: 'The standup shows work with something outstanding before Up Next, plus overdue and due-today work, blocked work, reviews and releases waiting, work sent back, unanswered client requests, onboarding steps waiting or late, people over their hours, sites or emails needing attention, and meetings to settle.',
        why: 'One screen should answer what needs attention today.',
      },
      {
        what: 'My tasks and Daily standup show a count in the sidebar: the number on My tasks\' Today tab, and the standup\'s items plus meetings to settle, for the client picked at the top. Nothing shows at zero.',
        why: 'You can see what waits for you without opening each screen.',
      },
      {
        what: 'From Up Next to Completed, recurring tasks and reminders included, work only shows on the standup once it is due today or late, or when it is Urgent; then everything about it shows. Blocked work always shows.',
        why: 'Work that is planned and moving doesn\'t need talking about every morning until its date arrives.',
      },
      {
        what: 'Each task on the standup says who it is waiting on, or \'Pending\' when nobody is in that seat yet.',
        why: 'Everyone can see whose move it is without opening the task.',
      },
      {
        what: 'Each task on the standup shows its priority, and each section runs by due date, then priority, urgent first. Undated items come last.',
        why: 'The most pressing work of the day is at the top.',
      },
      {
        what: 'Unfinished work that nothing else puts on the standup comes back as \'Untouched 30 days\' once nobody has changed it for 30 days, recurring tasks and reminders included.',
        why: 'Work that was triaged and then forgotten gets looked at again rather than sitting there for good.',
      },
    ],
  },
  {
    id: 'capacity',
    title: 'Capacity and hours',
    rules: [
      {
        what: 'A task counts against its people\'s time from Up Next until it is finished, spread evenly over the working days between its start and due dates.',
        why: 'Ideas that may never be built shouldn\'t make anyone look busy.',
      },
      {
        what: 'Days off and non-working days carry none of a task\'s hours; if nobody works any day in its window, the hours land on the first day.',
        why: 'The hours still exist and somebody still owes them, so they are never allowed to vanish.',
      },
      {
        what: 'Finished work stays on the days it was planned for up to today, shown in its own colour, while planned days after an early finish become free again.',
        why: 'A day that was used shouldn\'t look free, but time given back should.',
      },
      {
        what: 'Standing meetings and the days ahead of recurring tasks count too, and capacity always adds up every client, whichever client is picked.',
        why: 'A person can\'t look free for one client while they are busy for another.',
      },
      {
        what: 'Starting work is refused if it would put anyone on the task over their hours in any single week, unless an administrator gives a reason, which is recorded on the task and asked for again on every move.',
        why: 'Over-booking someone should be a deliberate decision by a person, never something that happens silently.',
      },
      {
        what: 'A week shows as tight from 80% of someone\'s hours and as over once it goes past 100%.',
        why: 'Tight weeks get noticed before they become over-booked ones.',
      },
      {
        what: 'Each person has a working week that can start or stop on a date, and no day can hold more than 12 hours; staff set their own, and only an administrator can set someone else\'s.',
        why: 'Changes to hours don\'t rewrite the past, and nobody is planned for an impossible day.',
      },
      {
        what: 'The reviewer\'s and deliverer\'s hours are suggested as 20% and 10% of the doer\'s and can be changed; a client reviewer and a task with no designer take no hours for those seats.',
        why: 'Planning is quick for the usual case and still right for the unusual one.',
      },
      {
        what: 'Only work where the client pays uses their support hours: it holds them from Up Next and spends them when work starts.',
        why: 'The balance shows what is committed as well as what has been used.',
      },
      {
        what: 'Paid work can\'t enter Up Next unless the client has enough hours left, and can\'t start unless they are also on a package they can spend from; work can never take a balance below zero.',
        why: 'We don\'t commit to work a client hasn\'t got the hours for.',
      },
      {
        what: 'A Manager sees only their own row on Capacity; administrators see everyone.',
        why: 'A person\'s load is their own business and the administrators\'.',
      },
    ],
  },
  {
    id: 'reminders',
    title: 'Reminders',
    rules: [
      {
        what: 'A reminder is a task for one day or a few days, for one or more people, on one client.',
        why: 'Some things just need doing on a date, without a repeat or a full journey.',
      },
      {
        what: 'Anyone on the team can add a reminder, but only the person who added it or an administrator can change or delete it.',
        why: 'Reminders are everyday work, but one person shouldn\'t rewrite another\'s.',
      },
      {
        what: 'Everyone named on a reminder must be able to work on its client.',
        why: 'Nobody should be given a task they can\'t open.',
      },
      {
        what: 'Each person gets their own copy, placed in Up Next, and ticking it finishes only their copy.',
        why: 'Everyone answers for their own part, and one person\'s tick doesn\'t hide it from the rest.',
      },
      {
        what: 'Every reminder has a type (General, Campaign, Marketing, Sales, Finance, Deadline or Other, General by default), and its title starts with that type.',
        why: 'Reminders can be told apart and sorted at a glance.',
      },
      {
        what: 'Reminders are free and carry no hours, so they never count against capacity or a client\'s support hours.',
        why: 'A reminder is a nudge, not planned work.',
      },
      {
        what: 'A reminder shows on the calendar on every day it covers, and moves into Today in My tasks when its first day arrives.',
        why: 'It is visible ahead of time and becomes a to-do when it matters.',
      },
      {
        what: 'Editing a reminder updates the copies nobody has ticked; removing a person removes their unticked copy, and deleting the reminder keeps ticked copies as the record.',
        why: 'Changes reach the people who still have it to do, without rewriting what was already done.',
      },
      {
        what: 'Once everyone named on a reminder has ticked it, it leaves the Reminders list. \'Show completed\' brings finished ones back.',
        why: 'The list is what is still to do, and the record stays a click away.',
      },
    ],
  },
  {
    id: 'requests',
    title: 'Client requests',
    rules: [
      {
        what: 'A client can send a bug, a request, an idea or a suggestion at any time, even with no support package.',
        why: 'A client who can\'t report a problem in Forge tells us by email, and then it isn\'t tracked at all.',
      },
      {
        what: 'What the client wrote is kept exactly as sent and can never be edited.',
        why: 'What was asked for mustn\'t drift to match what was delivered.',
      },
      {
        what: 'A request is received, in review, accepted, declined or converted, and only our staff can set that or write the reply the client sees on their own site.',
        why: 'Answering a request is our job, not the client answering themselves.',
      },
      {
        what: 'Turning a request into work makes a new task on the same client, or links it to an existing task there, starting at Future Idea or Triage; no parent task is asked for.',
        why: 'Work always lands with the client who asked, and doesn\'t need a bigger project around it.',
      },
      {
        what: 'A request sent straight to Triage only gets there if the new task already has what Triage needs, such as the two people named; otherwise it waits in Future Idea.',
        why: 'Converting records what the request already answered, but never skips a check.',
      },
      {
        what: 'Work made from a client\'s request counts its client as confirmed, and its client can never be changed.',
        why: 'The request stays linked to the work, so moving it would carry one client\'s words to another.',
      },
      {
        what: 'The client is emailed when we receive their request.',
        why: 'They know it arrived without having to chase us.',
      },
      {
        what: 'Client accounts never move tasks by any route; the one exception is approving or sending back a task they are reviewing.',
        why: 'Where work is in its journey is our decision, and that line is a security boundary rather than a preference.',
      },
    ],
  },
  {
    id: 'people',
    title: 'Who can do what',
    rules: [
      {
        what: 'Only administrators and Forge: Managers can open Forge; our existing staff were given the Manager role automatically.',
        why: 'Forge holds every client\'s work, so only our own people get in.',
      },
      {
        what: 'Administrators see every client, set up clients, people, packages, support, meetings and recurring tasks, can answer any seat\'s checks on a task, and are the only ones who can delete a task and everything beneath it.',
        why: 'Setup and irreversible actions sit with the people accountable for the whole studio.',
      },
      {
        what: 'Only an administrator can delete a client, after being shown how much goes with it; its sites, tasks, requests, meetings, recurring tasks, reminders, onboarding, hours, alerts and its own people all go too, and nothing of it shows in reports again. The studio\'s own client can\'t be deleted.',
        why: 'A test client shouldn\'t skew the numbers, and a delete can\'t be undone, so it is deliberate and complete.',
      },
      {
        what: 'A Manager sees and works on their own clients\' work only, and can read those clients\' details, meetings and support but not change them.',
        why: 'People work on what they are assigned to, and configuration stays with administrators.',
      },
      {
        what: 'Each person has a Role: a title, weekly hours, contract dates, a description and regular duties. Administrators set it on the People screen and each person can read their own on their profile; the hours are a note and change nothing about capacity.',
        why: 'Everyone can see what they are there to do, without it changing how time is counted.',
      },
      {
        what: 'Each client is worked on by All staff or by chosen people, set on the client\'s Edit screen, and only those people can be put on its tasks or reminders.',
        why: 'Nobody should be given work they can\'t open.',
      },
      {
        what: 'Every task has someone doing the work, a reviewer and a deliverer, plus an optional designer; the deliverer can be the same person as either of the others, and stand-ins can only be named by an administrator.',
        why: 'Each part of the job has a named owner, and cover is a deliberate decision.',
      },
      {
        what: 'The person doing the work can\'t also be the reviewer unless they hold the Principal permission for that client, and this applies to administrators too.',
        why: 'A review only counts if someone else looked, except for people who genuinely work alone.',
      },
      {
        what: 'The documentation, technical and design approvals belong to the task\'s reviewer, not to the designer or anyone else.',
        why: 'One person signs off the work from start to finish.',
      },
      {
        what: '\'The client\' can be picked as reviewer at any stage, including on a new task, stays reviewer if the task goes back, and their review takes nobody\'s hours.',
        why: 'Some work needs the client\'s own sign-off, and their time isn\'t ours to plan or charge.',
      },
      {
        what: 'When the client reviews, nobody at the studio can approve as them: the client approves or sends back from their Forge page once the task is In Review, they are emailed each time, and an administrator can record their answer for them.',
        why: 'The sign-off has to be genuinely the client\'s, even when they give it by email or phone.',
      },
      {
        what: 'With the client as reviewer, an administrator gives the documentation, technical and design approvals for them; staff can\'t.',
        why: 'The client only signs off at review, and the earlier checks still need someone accountable to give them.',
      },
      {
        what: 'A task shows in someone\'s My tasks only while it is their turn: the doer\'s until review, the designer\'s at Design Process, the reviewer\'s in review, the deliverer\'s once Completed, and the doer\'s while the client reviews.',
        why: 'Each person\'s list shows what they need to act on now, and each task appears once.',
      },
      {
        what: 'My tasks sorts by due date alone: Today is due today or late, then Tomorrow, the next seven days, and further out. Undated work is further out. Within a tab, work runs by due date, then priority, urgent first.',
        why: 'Today should be the day\'s work, not everything in progress.',
      },
    ],
  },
];
