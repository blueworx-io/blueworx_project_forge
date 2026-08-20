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
  priority: string;
  planned_start: string;
  planned_due: string;
  commercial_class: string;
  record_version: number;
  updated_at: number;
}

export interface WorkEvent {
  id: string;
  action: string;
  from_stage: string;
  to_stage: string;
  gate: string;
  reason: string;
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
