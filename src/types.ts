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
export type ViewName = 'board' | 'list' | 'gantt';

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
