export interface ForgeData {
  restUrl: string;
  nonce: string;
  isLoggedIn: boolean;
  canEdit: boolean;
  canManage: boolean;
  siteUrl: string;
  /** The WordPress admin, for the screens that still live there. */
  adminUrl?: string;
  /** The signed-in WordPress user, and the Forge person behind it (#309). */
  currentUserId?: number;
  person?: { id: string; display_name: string } | null;
  /** Who is signed in, as the corner of every screen shows it. */
  currentUser?: { name: string } | null;
  /** Their WordPress profile, where their own settings live. */
  profileUrl?: string;
  loginUrl: string;
  logoutUrl: string;
  version: string;
}

declare global {
  interface Window {
    bwxForgeData?: ForgeData;
  }
}

export interface Stage {
  id: string;
  label: string;
  kind: 'linear' | 'conditional' | 'exception';
}

/** One of a task's links. */
export interface LinkRow {
  label: string;
  url: string;
}

/** One of a task's images, an attachment in the media library. */
export interface ImageRow {
  id: number;
  url: string;
  name: string;
}

/** One line of a task's checklist. */
export interface ChecklistRow {
  text: string;
  done: boolean;
}

export interface WorkItem {
  id: string;
  client_site_id: string;
  /** Present on the all-clients board only: whose work this is. */
  client_name?: string;
  site_name?: string;
  parent_id: string;
  level: string;
  level_label: string;
  work_type: string;
  work_type_label: string;
  title: string;
  problem: string;
  /** Up to ten one-line items, ticked off in the panel. */
  checklist: ChecklistRow[];
  /** Which recurring arrangement made this, or '' for work somebody created. */
  recurring_id?: string;
  /** A recurring chore's people, who has ticked it (person id to unix time), and the hours each spends. */
  assignees: string[];
  ticks: Record< string, number >;
  hours_each: number;
  scope: string;
  requirements: string;
  acceptance_criteria: string;
  non_goals: string;
  references: string;
  /** Since 2026-09-19: the design link, how to test and its steps, the links and the images. */
  design_url: string;
  test_description: string;
  test_steps: ChecklistRow[];
  links: LinkRow[];
  images: ImageRow[];
  stage: string;
  stage_label: string;
  prior_stage: string;
  blocked_elapsed: number;
  terminal_outcome: string;
  terminal_label: string;
  duplicate_of: string;
  archived: boolean;
  review_attempt: number;
  priority: string;
  planned_start: string;
  planned_due: string;

  /*
   * When it is meant to be reviewed and when it is meant to ship. Stored
   * alongside the planned dates and read by the calendar (#121), which treats
   * all four as dates in their own right rather than as detail hanging off the
   * due date.
   */
  review_target?: string;
  release_target?: string;
  commercial_class: string;
  record_version: number;
  updated_at: number;

  /*
   * The seats, the hours against each, and what the work costs to finish.
   * Every one of these has been in the record and in the API since M3; they
   * are declared here because the item panel now sets them, and until it did
   * the only thing that ever wrote them was a test calling the API.
   */
  primary_user_id: string;
  reviewer_id: string;
  deliverer_id: string;
  reviewer_substitute_id?: string;
  deliverer_substitute_id?: string;
  hours_primary: number;
  hours_review: number;
  hours_delivery: number;
  delivered_by_forge: boolean;
  release_method: string;
  release_destination: string;

  /*
   * What the children beneath it make it (#101). Filled in by the API on the
   * way out and never stored, so there is nothing here anybody can write —
   * which is why these are optional: an item read from a response that predates
   * them has none, and a screen has to cope with that rather than show 0%.
   */
  progress?: number;
  derived_state?: 'empty' | 'not-started' | 'in-progress' | 'completed';
  derived_start?: string;
  derived_due?: string;

  /*
   * The ids this waits on (#120). Sent with every item in a list rather than
   * only on a single read, because a schedule needs the sequence for everything
   * on screen at once and one request per bar is not a way to draw a chart.
   * Ids only — the titles are already in the list holding this.
   */
  waits_on?: string[];
}

/**
 * A filter set, in the shape the API takes it.
 *
 * Set-valued filters take a list because "triage or up next" is one question
 * rather than two views; the free ones take a single value.
 */
export interface WorkFilters {
  stage?: string[];
  level?: string[];
  work_type?: string[];
  priority?: string[];
  commercial_class?: string[];
  person?: string[];
  parent_id?: string[];
  search?: string;
  due_from?: string;
  due_to?: string;
  start_from?: string;
  start_to?: string;
}

/** Somebody's own shortcut to a way of looking at the work (#123). */
export interface SavedView {
  id: string;
  name: string;
  filters: WorkFilters;
  grouping: string;
}

/** Which view of the work is on screen. */
export type ViewName = 'board' | 'list' | 'gantt' | 'calendar';

/** Which screen of the studio is on screen (#131, #139). */
export type ScreenName = 'mytasks' | 'work' | 'requests' | 'capacity' | 'onboarding' | 'standup' | 'reports' | 'recurring' | 'reminders' | 'subscriptions' | 'availability' | 'packages' | 'people' | 'clients' | 'support' | 'meetings' | 'profile';

/** What to call a person's position in a period (#139). */
export type CapacityBand = 'clear' | 'tight' | 'over' | 'unrecorded';

/** Available against committed, for one person over one period. */
export interface CapacityPosition {
  available: number;
  committed: number;
  remaining: number;
  band: CapacityBand;
}

/** One cell of the grid: a position, and the day or week it covers. */
export interface CapacityCell extends CapacityPosition {
  from: string;
  to: string;
}

/** One row of the grid. */
export interface CapacityPerson {
  user_id: string;
  display_name: string;
  periods: CapacityCell[];
  total: CapacityPosition;
}

/** How the capacity grid is cut: a column per day, or per week. */
export type CapacityBy = 'days' | 'weeks';

/** The capacity read. */
export interface CapacityResponse {
  from: string;
  to: string;
  by: CapacityBy;
  periods: { from: string; to: string }[];
  people: CapacityPerson[];
}

/** One piece of work behind a committed figure. */
export interface CapacityAllocation {
  item_id: string;
  title: string;
  client_id: string;
  role: 'primary' | 'review' | 'delivery';
  covering: string;
  hours: number;
  from: string;
  to: string;
}

/** One day of somebody's availability, with the reason for any zero. */
export interface CapacityDay {
  date: string;
  hours: number;
  base_hours: number;
  reason: string;
}

/** Everything behind one person's numbers. */
export interface CapacityDrilldown {
  user_id: string;
  display_name: string;
  from: string;
  to: string;
  days: CapacityDay[];
  committed_by_day: Record< string, number >;
  allocations: CapacityAllocation[];
  position: CapacityPosition;
}

/** An intake state, with the words a person reads for it. */
export interface IntakeState {
  slug: string;
  label: string;
}

/**
 * Something a client has asked for (#129), as the studio's queue sees it.
 *
 * The first five fields are the client's own words and are never editable
 * anywhere — not here, not by them (REQ-1). The studio writes `intake_state`
 * and `response`, and nothing else on this record.
 */
export interface Submission {
  id: string;
  client_id: string;
  client_site_id: string;
  client_name: string;
  type: string;
  title: string;
  description: string;
  desired_outcome: string;
  evidence: string;
  submitted_by: string;
  intake_state: string;
  intake_label: string;
  response: string;
  converted_item_id: string;
  created_at: number;
  updated_at: number;
}

/**
 * What turning a request into work asks for (#132).
 *
 * There is no client and no site here, and there is no version of this type
 * that has one. The pipeline the work lands in comes off the submission on the
 * server; a field here that could name one would be the thing D-40 exists to
 * make impossible.
 */
export interface ConversionRequest {
  entry_stage: string;

  /** Link work that already exists, instead of making some. */
  item_id?: string;

  /** Hang it under work that already exists… */
  parent_id?: string;

  /** …or under a parent made on the way. Both, and it is refused. */
  parent_title?: string;
  parent_level?: string;

  /** The card's own title, where it should differ from what was asked. */
  title?: string;
  work_type?: string;

  /** Entering at Triage names who does it and who reviews it (2026-09-19). */
  primary_user_id?: string;
  reviewer_id?: string;
}

/** The queue's own filter set — not the board's, which filters work items. */
export interface QueueFilters {
  client_id?: string[];
  intake_state?: string[];
  type?: string[];
  search?: string;
}

/** One person with more committed than they have time for, in one week. */
export interface OverBooked {
  user_id: string;
  display_name: string;
  week_from: string;
  week_to: string;
  available: number;
  committed: number;
  excess: number;
}

/** One thing that has to be true before work leaves a stage. */
export interface Requirement {
  id: string;
  label: string;
  satisfied_by: string;
  type: string;
  evidence: boolean;
  who: string;
  /**
   * Whether it is satisfied by a field, a recorded completion, the system, or
   * worked out from the task itself.
   */
  by: 'field' | 'record' | 'system' | 'auto';
  fields: string[];
  /** For a system or worked-out one, which check answers it. */
  check?: string;
  /** Whether the task meets it now; present when the whole gate is listed. */
  met?: boolean;
  /** For a recorded one, how a screen asks: a dropdown on the row, or a box on the task. */
  control?: '' | 'pick' | 'box';
  /** A pick's fixed choices. */
  options?: Array< { value: string; label: string } >;
  /** What a pick appends after its fixed choices: the site's other items, or people. */
  source?: '' | 'items' | 'people';
  /** What kind of box: text, number, date, datetime or range. */
  input?: string;
  /**
   * Who the capacity check found no room for, and in which weeks. Only the
   * capacity requirement carries it — a refusal that named nobody would leave
   * somebody to go looking for the person it meant.
   */
  over?: OverBooked[];
  /**
   * What the support-hours check found: how many hours the work needs, how many
   * the site could draw on, and why it was refused. Only the support-hours
   * requirement carries it, and it is separate from `over` on purpose — the two
   * fail for different reasons and are fixed by different people.
   */
  hours?: SupportHours;
}

/** Why a site cannot pay for a piece of work as planned. */
export interface SupportHours {
  needed: number;
  held: number;
  available: number;
  shortfall: number;
  state: string;
  because: '' | 'not_enough' | 'no_package';
  sufficient: boolean;
}

/** A check the system runs for itself, always reported either way. */
export interface GateCheck {
  id: string;
  label: string;
  result: 'pass' | 'fail';
  note: string;
}

/** What one possible move is still waiting on. */
export interface Readiness {
  unmet: Requirement[];
  checks: GateCheck[];
  /** Every requirement of the gate, met or not. */
  all?: Requirement[];
}

/** Somebody's completion of a requirement, with their name and the time on it. */
export interface GateRecord {
  id: string;
  requirement: string;
  value: string;
  evidence: string;
  actor: number;
  completed_at: number;
}

export interface Comment {
  id: string;
  kind: string;
  visibility: 'internal' | 'client';
  body: string;
  url: string;
  author_name: string;
  created_at: number;

  /**
   * Which side of the connection it came from (#133).
   *
   * A person on a client's own site has no account here, so `author_name` is a
   * name the client site told us and there is no user id behind it. This says
   * so plainly rather than leaving a screen to infer it from an author of zero.
   */
  from_client?: boolean;

  /** The question this answers, where it answers one. */
  answers?: string;
}

export interface WorkEvent {
  id: string;
  action: string;
  from_stage: string;
  to_stage: string;
  gate: string;
  outcome: string;
  reason: string;
  detail: string;
  /** Which field, when the action is an edit. */
  field: string;
  occurred_at: number;
  /** Who did it, by display name; empty when it was the system. */
  actor_name: string;
}

/** How often a recurring task is due (PR 3). */
export type RecurringRule =
  | { every: 'day' }
  | { every: 'week'; days: number[] }
  | { every: 'month'; day: number };

/** One recurring arrangement on the studio's site. */
export interface RecurringSource {
  id: string;
  kind: 'schedule' | 'subscription' | 'reminder';
  client_site_id: string;
  client_id: string;
  title: string;
  description: string;
  work_type: string;
  primary_user_id: string;
  reviewer_id: string;
  deliverer_id: string;
  hours_primary: number;
  hours_review: number;
  hours_delivery: number;
  /** Who does it, each ticking their own, and the hours each spends (2026-09-18). */
  assignees: string[];
  hours_each: number;
  /** The checklist every task it makes starts with (2026-09-19). */
  checklist: ChecklistRow[];
  rule: RecurringRule;
  cadence: string;
  starts_on: string;
  ends_on: string;
  next_due: string;
  last_created_at: number;
  status: 'active' | 'paused' | 'ended';
  source_ref: string;
  record_version: number;
  last?: { due_on: string; work_item_id: string } | null;
  [ key: string ]: unknown;
}

/** A task on a fixed day or period, for one or more people (2026-09-25). */
export interface Reminder {
  id: string;
  client_site_id: string;
  client_id: string;
  title: string;
  description: string;
  category: 'general' | 'campaign' | 'marketing' | 'deadline' | 'other';
  assignees: string[];
  starts_on: string;
  /** '' for a one-day reminder. */
  ends_on: string;
  record_version: number;
  created_by: number;
  /** Whether the signed-in person may change it: its author, or an administrator. */
  can_edit: boolean;
  copies: Array< { item_id: string; person: string; done: boolean } >;
}

/** A connected SureCart store, as the Subscriptions screen sees it (PR 4). */
export interface SubscriptionConnection {
  id: string;
  kind: string;
  name: string;
  status: string;
  last_ok_at: number;
  last_error: string;
  last_count: number;
}

/** One subscription, as SureCart last described it. */
export interface Subscription {
  id: string;
  connection_id: string;
  external_id: string;
  customer_name: string;
  customer_email: string;
  product_name: string;
  amount: number;
  currency: string;
  interval: string;
  status: string;
  renews_on: string;
  fetched_at: number;
  reminder: { work_item_id: string; due_on: string; stage: string } | null;
  [ key: string ]: unknown;
}

export interface ClientSite {
  id: string;
  client_id: string;
  name: string;
  status: string;
}

export interface Client {
  id: string;
  display_name: string;
  status: string;
}

/* ---- Clients screen (PR 4 of spec 2026-09-16) ---- */

/** Who looks after a client on our side, as `Contacts::resolve()` answers it. */
export interface ClientContact {
  /** The person named, still here or not; null when nobody has been named or they were cleared. */
  contact: Person | null;
  /** True when nobody active is named, so the studio stands in. */
  needs_reassignment: boolean;
  fallback: 'studio' | '';
}

/** The whole client record, as every write to a client answers it. */
export interface ClientRow extends Client {
  legal_name: string;
  timezone: string;
  email_domains: string[];
  record_version: number;
}

/** A client as the Clients screen holds it: the record, and the two facts the list joins on. */
export interface ClientRecord extends ClientRow {
  is_studio: boolean;
  contact: ClientContact;
  [ key: string ]: unknown;
}

/** One site's connection record, with its health worked out on the server (#89). */
export interface SiteIntegration {
  id: string;
  client_site_id: string;
  registry_site_id: string;
  key_state: 'unissued' | 'active' | 'revoked';
  key_issued_at: number;
  key_rotated_at: number;
  key_revoked_at: number;
  last_seen_at: number;
  mail_capable: 'unknown' | 'yes' | 'no';
  health: 'unconfigured' | 'revoked' | 'never_connected' | 'connected' | 'broken' | 'idle';
  health_label: string;
  record_version: number;
  [ key: string ]: unknown;
}

/** Where a site is with its onboarding (#160); null when no checklist has been published to give. */
export interface SiteOnboarding {
  started: boolean;
  ready: boolean;
  template_version: number;
  /** Percentage of required steps done; a float, for showing rather than comparing. */
  completion: number;
  /** Launch-critical steps still outstanding. */
  blocking: number;
}

/** A site as the Clients screen holds it, from `GET /clients/<id>/sites`. */
export interface ClientSiteRecord extends ClientSite {
  url: string;
  record_version: number;
  integration: SiteIntegration | null;
  onboarding: SiteOnboarding | null;
  [ key: string ]: unknown;
}

/** What `POST /client-sites/<id>/integration/key` answers: the key, once, and nowhere else. */
export interface IssuedKey {
  ok: true;
  rotated: boolean;
  key: string;
  integration: SiteIntegration;
}

/* ---- Onboarding board (#165) ---- */

/** One step on one client's checklist, as the studio's board reads it. */
export interface OnboardingStep {
  id: string;
  client_site_id: string;
  section: string;
  title: string;
  status: string;
  owner_side: string;
  owner_id: string;
  launch_critical: number;
  optional: number;
  allows_not_applicable: number;
  due_on: string;
  response: string;
  position: number;
  record_version: number;

  /** Worked out from today's date, never stored (Onboarding\Statuses). */
  overdue: boolean;
}

/**
 * One site's row on the board.
 *
 * Every figure here describes the whole checklist, and `steps` is the part that
 * narrows when somebody filters. Reading a figure off the length of `steps`
 * would give the same client a different completion depending on what was last
 * clicked, which is the one thing this screen must never do.
 */
export interface OnboardingSite {
  client_id: string;
  client_name: string;
  client_site_id: string;
  site_name: string;
  site_url: string;
  template_id: string;
  template_name: string;
  template_version: number;
  contact_id: string;
  contact_name: string;
  assigned_at: number;
  may_review: boolean;

  required: number;
  approved: number;
  completion: number;
  launch_ready: boolean;
  blocking: { id: string; title: string }[];
  total: number;
  awaiting_review: number;
  overdue: number;
  blocked: number;
  next_due: string;

  steps: OnboardingStep[];
}

/** One option in one of the board's dropdowns. */
export interface OnboardingChoice {
  id: string;
  label: string;
}

export interface OnboardingBoard {
  denied?: boolean;
  sites: OnboardingSite[];
  statuses: string[];
  total: number;
  totals: {
    sites: number;
    launch_ready: number;
    awaiting_review: number;
    overdue: number;
    blocked: number;
  };
  facets: {
    clients: OnboardingChoice[];
    templates: OnboardingChoice[];
    contacts: OnboardingChoice[];
    owners: OnboardingChoice[];
  };
}

/**
 * The board's own filter set.
 *
 * Sent to the server rather than applied here, unlike the request queue's. The
 * board's figures are worked out from every step a site has, including the ones
 * a filter hides, so filtering in the browser would mean shipping every step of
 * every client to draw a screen showing four of them.
 */
export interface OnboardingFilters {
  client_id?: string;
  template_id?: string;
  contact_id?: string;
  owner_id?: string;
  owner_side?: string;
  status?: string;
  overdue?: 'yes';
  blocked?: 'yes';
  launch?: 'ready' | 'not-ready';
}

/* ---- Standup (#169, #170) ---- */

/**
 * One thing needing attention, and why.
 *
 * There is no "seen" or "dismissed" field, and there is not going to be one.
 * A card exists because a condition is true; the server works the list out
 * fresh every time it is asked, so anything stored here would be a second
 * answer to a question that already has one.
 */
export interface Signal {
  id: string;
  /** work_item or submission. */
  kind: string;
  action: string;
  subject_id: string;
  title: string;
  client_id: string;
  client_site_id: string;
  at: number;
  actor: number;
  detail: string;
  /** One the studio agreed would be visible when it happened — WF-5, CAP-4. */
  governance: boolean;
  unread: boolean;
}

export interface SignalList {
  denied?: boolean;
  /** The moment this answer was worked out, handed back when marking it read. */
  generated: number;
  seen_at: number;
  unread: number;
  kinds: string[];
  signals: Signal[];
}

export interface StandupCard {
  rule: string;
  subject_type: string;
  subject_id: string;
  detail: Record< string, unknown >;
}

export interface StandupList {
  denied?: boolean;
  today: string;
  generated: number;
  rules: string[];
  cards: StandupCard[];
  /** Today's diary: chores, dates, meetings, renewals and who is away (2026-09-18). */
  diary?: DiaryEntry[];
  /** Meetings that have happened and are not settled yet (2026-09-24). */
  to_settle?: Array< DiaryEntry & { site_id: string; series_id: string; slot: string; can_settle: boolean } >;
}

/** One thing on the studio's diary, whatever kind it is (2026-09-18). */
export interface DiaryEntry {
  id: string;
  kind: 'recurring' | 'reminder' | 'date' | 'meeting' | 'subscription' | 'leave';
  label: string;
  date: string;
  /** Last day of a span, or '' for one day. */
  ends_on: string;
  title: string;
  detail: string;
  /** Person ids, or 'all'. */
  people: string[] | 'all';
  /** The work item behind a chore, for opening it. */
  item_id: string;
}

/** A company day, birthday, campaign or other date the studio keeps. */
export interface CalendarDate {
  id: string;
  kind: string;
  kind_label: string;
  title: string;
  on_date: string;
  ends_on: string;
  people: string[] | 'all';
  note: string;
  record_version: number;
}

/**
 * One summarised duration: the middle of a set, and how many were in it.
 *
 * The count travels with the median rather than being fetched separately,
 * because a median without its sample size is a number people quote.
 */
export type ReportSummary = {
  median_hours: number | null;
  count: number;
};

/** The delivery numbers (#176), exactly as the server counts them. */
export type Reports = {
  empty: boolean;
  from: number;
  to: number;
  stage_distribution: Record< string, number >;
  time_in_stage: Record< string, ReportSummary >;
  cycle_time: ReportSummary;
  blocked_time: ReportSummary;
  review_turnaround: ReportSummary;
  planned_vs_actual: {
    count: number;
    on_time: number;
    late: number;
    median_days_late: number | null;
  };
  throughput: {
    weeks: Array< { from: number; to: number; released: number } >;
  };

  /**
   * The operational six (#261) — the numbers about running the studio rather
   * than about delivering work. They arrive in the same answer as the delivery
   * set because they are one read over one window: two endpoints would be two
   * chances to get the tenant boundary wrong.
   */
  capacity_utilisation: {
    people: number;
    committed: number;
    available: number;
    /** Null when nobody has hours set up — not nought, and not one. */
    share: number | null;
    over: number;
  };
  overrides: {
    workflow: number;
    capacity: number;
    /** Times, not items: one job pushed through three times is three decisions. */
    occasions: number;
    items: number;
  };
  hours: {
    granted: number;
    work_used: number;
    meeting_used: number;
    work_held: number;
    meeting_held: number;
    adjusted: number;
    spent: number;
    /** Committed and not yet spent. Deliberately not added to `spent`. */
    held: number;
  };
  onboarding_readiness: {
    sites: number;
    ready: number;
    not_ready: number;
    median: number | null;
  };
  request_funnel: {
    total: number;
    states: Record< string, number >;
    kinds: Record< string, number >;
  };
  email_delivery: {
    total: number;
    outcomes: Record< string, number >;
    delivered: number;
    failed: number;
    /** Null when nothing was sent: a hundred per cent of nothing reassures wrongly. */
    share: number | null;
  };
};

export type ReportsResponse = {
  ok: boolean;
  generated: number;
  reports: Reports;
};

/**
 * Somebody who can hold a seat on a piece of work.
 *
 * The studio's own people, not a client's — a seat is answerable for the work
 * and the studio is what answers for it. Read from /users, which is the same
 * list the People screen manages.
 */
export interface Person {
  id: string;
  display_name: string;
  status: string;
  /** The WordPress account this person signs in with; 0 when none. */
  wp_user_id?: number;
}

/* ---- People (PR 3 of spec 2026-09-16) ---- */

/** The WordPress account a person signs in with, as the person answer names it. */
export interface PersonAccount {
  id: number;
  login: string;
}

/** A person's role with one client (#90), labelled for reading. */
export interface Membership {
  id: string;
  user_id: string;
  client_id: string;
  /** Empty when the membership reaches every site the client has. */
  client_site_id: string;
  role: string;
  role_label: string;
  /** Comma-separated, as stored. */
  grants: string;
  status: string;
  created_at: number;
  updated_at: number;
  created_by: number;
  record_version: number;
  client_name: string;
  site_name: string | null;
  [ key: string ]: unknown;
}

/** A person as the People screen holds them: the record, their account, and everywhere they work. */
export interface PersonRecord extends Person {
  email: string;
  /** Comma-separated, as stored. */
  grants: string;
  wp_user_id: number;
  record_version: number;
  account: PersonAccount | null;
  memberships: Membership[];
}

/** What every write to a person answers, and `GET /users/<id>`. */
export interface PersonAnswer {
  ok: true;
  user: Omit< PersonRecord, 'memberships' >;
  memberships: Membership[];
}

/** One grant, with what it means (#93). */
export interface GrantOption {
  grant: string;
  label: string;
  description: string;
}

/** What `GET /grants` answers: the grants held on the person, and the ones held with a client. */
export interface GrantsAnswer {
  ok: true;
  on_user: GrantOption[];
  on_membership: GrantOption[];
}

/** A WordPress account nobody in Forge holds yet, from `GET /accounts`. */
export interface UnlinkedAccount {
  id: number;
  login: string;
  display_name: string;
  user_email: string;
}

/** One working-week pattern, from a date (#136). Sunday first, as PHP numbers weekdays. */
export interface AvailabilityPattern {
  id: string;
  user_id: string;
  effective_from: string;
  /** The last day it applies, or '' for ongoing. */
  effective_to: string;
  note: string;
  hours_sun: number;
  hours_mon: number;
  hours_tue: number;
  hours_wed: number;
  hours_thu: number;
  hours_fri: number;
  hours_sat: number;
  hours_week: number;
  created_at: number;
  created_by: number;
}

export type LeaveKind = 'leave' | 'public-holiday' | 'training' | 'other';

/** A period somebody is not available for, inclusive of both ends. */
export interface LeaveRecord {
  id: string;
  user_id: string;
  starts_on: string;
  ends_on: string;
  kind: LeaveKind;
  note: string;
  created_at: number;
  created_by: number;
}

/** What `/users/<id>/availability` answers, and what every write there answers too. */
export interface AvailabilityAnswer {
  ok: true;
  person: { id: string; display_name: string };
  recorded: boolean;
  current: AvailabilityPattern | null;
  history: AvailabilityPattern[];
  leave: LeaveRecord[];
  week: {
    from: string;
    to: string;
    hours: number;
    days: Array< { date: string; hours: number; base_hours: number; reason: string } >;
  };
}

/* ---- Packages (PR 2 of spec 2026-09-16) ---- */

export type PackageStatus = 'active' | 'retired';

/** One frozen version of a package. Written once; never edited (COMM-1). */
export interface PackageVersion {
  id: string;
  package_id: string;
  version: number;
  name: string;
  hours: number;
  price: number;
  currency: string;
  validity_months: number;
  /** Hours per year (the whole term) or per month (2026-09-19), price likewise (2026-09-21), and what follows from each. */
  hours_per: 'year' | 'month';
  hours_total: number;
  price_per: 'year' | 'month';
  price_total: number;
  price_per_hour: number;
  terms: string;
  created_at: number;
  created_by: number;
}

export interface SupportPackage {
  id: string;
  name: string;
  status: PackageStatus;
  position: number;
  retired_at: number;
  created_at: number;
  updated_at: number;
  created_by: number;
  record_version: number;
  current: PackageVersion | null;
  versions: PackageVersion[];
}

export interface PackagesAnswer {
  ok: true;
  packages: SupportPackage[];
  package?: SupportPackage;
  changed?: boolean;
}

/* ---- Support (PR 5 of spec 2026-09-16) ---- */

/** A site's position today, as `Commerce\Support` names it. */
export type SupportState = 'none' | 'scheduled' | 'active' | 'suspended' | 'lapsed';

export interface SupportPosition {
  state: SupportState;
  label: string;
  may_use_hours: boolean;
  covered_until: string | null;
  balance: number;
}

/** One period a site has been in, with the package's name and version beside it. */
export interface SupportPeriod {
  id: string;
  client_site_id: string;
  client_id: string;
  package_version_id: string;
  state: SupportState;
  starts_on: string;
  ends_on: string;
  term_ends_on: string;
  began_because: string;
  ended_because: string;
  hours_granted: number;
  price_charged: number;
  currency: string;
  prorated: boolean;
  note: string;
  created_at: number;
  updated_at: number;
  created_by: number;
  record_version: number;
  package_name: string;
  package_version: number;
}

/** One line of the hour ledger. `hours` is signed. */
export interface LedgerEntry {
  id: string;
  client_site_id: string;
  event_type: string;
  hours: number;
  source_type: string;
  source_id: string;
  reason: string;
  expires_at: number;
  actor: number;
  occurred_at: number;
  created_at: number;
  created_by: number;
  when: string;
  source: string;
  /** The meeting or task the line was for, by name; '' where there is nothing to name. */
  about: string;
}

/** A package on offer, as the assign form lists it: the catalogue entry and its version in force. */
export interface SupportOffer {
  id: string;
  name: string;
  current: { id: string; hours: number; price: number; currency: string; validity_months: number };
}

/** What `/client-sites/<id>/support` answers, and what every write there answers too. */
export interface SupportAnswer {
  ok: true;
  site: { id: string; name: string; client_id: string };
  position: SupportPosition;
  periods: SupportPeriod[];
  ledger: LedgerEntry[];
  packages: SupportOffer[];
  assignment?: SupportPeriod;
  entry?: LedgerEntry;
}

/** What assigning would grant, before anything is written (COMM-2). */
export interface SupportPreview {
  ok: true;
  hours: number;
  price: number;
  currency: string;
  ends_on: string;
  prorated: boolean;
}

/* ---- Meetings (PR 6 of spec 2026-09-17) ---- */

/** The four patterns a series may use, as `Meetings\Recurrence` names them. */
export type MeetingFrequency = 'weekly' | 'fortnightly' | 'four-weekly' | 'monthly';

/** What became of one meeting, as `Meetings\Occurrence` names it. */
export type MeetingStatus = 'scheduled' | 'held' | 'cancelled' | 'no-show';

/** What the ledger holds against one meeting, as `Meetings\MeetingHours` names it. */
export type MeetingLedgerState = 'forecast' | 'reserved' | 'used' | 'released';

/** One standing meeting, with the host named and its pattern in words. */
export interface MeetingSeries {
  id: string;
  client_site_id: string;
  client_id: string;
  title: string;
  frequency: MeetingFrequency;
  frequency_label: string;
  starts_on: string;
  ends_on: string;
  time_of_day: string;
  duration_mins: number;
  timezone: string;
  host_user_id: string;
  host_name: string;
  attendees: string;
  /** Who else comes, as people (2026-09-19). */
  attendee_ids: string[];
  planned_hours: number;
  hours_each: number;
  state: 'active' | 'ended';
  created_at: number;
  updated_at: number;
  created_by: number;
  record_version: number;
}

/**
 * One meeting inside the horizon. `slot` is the date the rule put it on and
 * the key a move or a settle names it by; `on` is the day it is actually on.
 * `id` is null for a forecast the reconcile has not given a row to yet.
 */
export interface Meeting {
  id: string | null;
  series_id: string;
  series_title: string;
  slot: string;
  on: string;
  time: string;
  status: MeetingStatus;
  status_label: string;
  hours: number;
  ledger_state: MeetingLedgerState;
  excepted_from: string | null;
  moved: boolean;
}

/** What `/client-sites/<id>/meetings` answers, and what every write there answers too. */
export interface MeetingsAnswer {
  ok: true;
  site: { id: string; name: string };
  series: MeetingSeries[];
  meetings: Meeting[];
  horizon: { from: string; to: string };
  /** Meetings gone by, twelve weeks to a page, newest first (2026-09-24). */
  past: { page: number; from: string; to: string; more: boolean; meetings: Meeting[] };
  people: Array< { id: string; display_name: string } >;
  added?: MeetingSeries;
  meeting?: Meeting | null;
}
