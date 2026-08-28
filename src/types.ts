export interface ForgeData {
  restUrl: string;
  nonce: string;
  isLoggedIn: boolean;
  canEdit: boolean;
  canManage: boolean;
  siteUrl: string;
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

export interface WorkItem {
  id: string;
  client_site_id: string;
  parent_id: string;
  level: string;
  level_label: string;
  work_type: string;
  work_type_label: string;
  title: string;
  problem: string;
  scope: string;
  requirements: string;
  acceptance_criteria: string;
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
export type ScreenName = 'work' | 'requests' | 'capacity';

/** What to call a person's position in a period (#139). */
export type CapacityBand = 'clear' | 'tight' | 'over' | 'unrecorded';

/** Available against committed, for one person over one period. */
export interface CapacityPosition {
  available: number;
  committed: number;
  remaining: number;
  band: CapacityBand;
}

/** One cell of the grid: a position, and the week it covers. */
export interface CapacityCell extends CapacityPosition {
  from: string;
  to: string;
}

/** One row of the grid. */
export interface CapacityPerson {
  user_id: string;
  display_name: string;
  weeks: CapacityCell[];
  total: CapacityPosition;
}

/** The capacity read. */
export interface CapacityResponse {
  from: string;
  to: string;
  weeks: { from: string; to: string }[];
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
}

/** The queue's own filter set — not the board's, which filters work items. */
export interface QueueFilters {
  client_id?: string[];
  intake_state?: string[];
  type?: string[];
  search?: string;
}

/** One thing that has to be true before work leaves a stage. */
export interface Requirement {
  id: string;
  label: string;
  satisfied_by: string;
  type: string;
  evidence: boolean;
  who: string;
  /** Whether it is satisfied by a field, a recorded completion, or the system. */
  by: 'field' | 'record' | 'system';
  fields: string[];
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
  occurred_at: number;
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
