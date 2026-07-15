export type ItemType = 'feature' | 'subitem' | 'bug' | 'feedback' | 'release';

// Stages are user-configurable strings, no longer a fixed union
export type WorkflowStage = string;

export type FeaturePrice = 'scoping' | 'premium' | 'teaser' | 'free';
export type BugStatus = 'open' | 'in-progress' | 'resolved';
export type FeedbackStatus = 'open' | 'in-progress' | 'resolved';
export type Priority = 'low' | 'medium' | 'high';

export interface ItemLink {
  label: string;
  url: string;
}

export interface Feature {
  id: string;
  type: 'feature';
  name: string;
  description: string;
  workflowStage: WorkflowStage;
  category: string;
  featurePrice: FeaturePrice;
  timeEstimate: number;
  releaseId?: string;
  subItemIds?: string[];
  isEnabled: boolean;
  isTrackedAsStat: boolean;
  createdDate: string;
  images?: string[];
  brands?: string[];
  stageDates?: Record<string, string>;
  changeLog?: string;
  links?: ItemLink[];
  /** IDs of items that block this item ("blocked by"). The reverse ("blocks") is derived. */
  dependsOn?: string[];
}

export interface SubItem {
  id: string;
  type: 'subitem';
  name: string;
  description: string;
  parentFeatureId: string;
  workflowStage: WorkflowStage;
  category: string;
  featurePrice: FeaturePrice;
  timeEstimate: number;
  releaseId?: string;
  images?: string[];
  brands?: string[];
  links?: ItemLink[];
  /** IDs of items that block this item ("blocked by"). The reverse ("blocks") is derived. */
  dependsOn?: string[];
}

export interface Bug {
  id: string;
  type: 'bug';
  title: string;
  description: string;
  linkedFeatureId?: string;
  releaseId?: string;
  bugStatus: BugStatus;
  workflowStage: WorkflowStage;
  priority: Priority;
  timeEstimate: number;
  reportedDate: string;
  notes?: string;
  images?: string[];
  urls?: string[];
  linkedSubItemId?: string;
  links?: ItemLink[];
  /** IDs of items that block this item ("blocked by"). The reverse ("blocks") is derived. */
  dependsOn?: string[];
}

export interface Feedback {
  id: string;
  type: 'feedback';
  title: string;
  description: string;
  linkedFeatureId?: string;
  linkedBugId?: string;
  releaseId?: string;
  status: FeedbackStatus;
  workflowStage: WorkflowStage;
  priority: Priority;
  timeEstimate: number;
  reportedDate: string;
  notes?: string;
  images?: string[];
  urls?: string[];
  linkedSubItemId?: string;
  links?: ItemLink[];
  /** IDs of items that block this item ("blocked by"). The reverse ("blocks") is derived. */
  dependsOn?: string[];
}

export interface Release {
  id: string;
  type: 'release';
  name: string;
  releaseName?: string;
  versionNumber?: string;
  versionType?: string;
  quarter: string;
  startWeek: string;
  endWeek: string;
  status: string;
  totalTimeEstimate: number;
  capacity: number;
  isBigWedgeCampaign: boolean;
  linkedFeatureIds: string[];
  linkedBugIds: string[];
  linkedFeedbackIds: string[];
  links?: ItemLink[];
  /** IDs of items that block this item ("blocked by"). The reverse ("blocks") is derived. */
  dependsOn?: string[];
}

export interface CompanyDate {
  id: string;
  title: string;
  date: string;
  description?: string;
  tracked?: boolean;
}

export type Item = Feature | SubItem | Bug | Feedback | Release;

export interface WorkflowStatus {
  id: string;
  label: string;
}

// Brand with optional logo URL
export interface BrandConfig {
  name: string;
  logo?: string;
}

export interface AppSettings {
  projectName?: string;
  parentBrand: string;
  teamMonthlyHours: number;
  /** Day of week releases start/end on (0=Sunday … 6=Saturday). Default 1 (Monday). */
  releaseDay?: number;
  brands: BrandConfig[];
  categories: string[];
  statuses: WorkflowStatus[];
}

export interface ArchivedItem {
  id: string;
  itemType: 'feature' | 'subitem' | 'bug' | 'feedback' | 'release' | 'company_date';
  name: string;
  archivedAt: string;
}

export interface Connection {
  id: string;
  name: string;
  url: string;
  /** Write-only. Send to set/replace the token; omit to leave unchanged. Never returned by the API. */
  authToken?: string;
  /** Read-only display hint, e.g. "••••a1b2". Present only if a token is stored. */
  authTokenHint?: string;
  /** Which item creations fire this connection. */
  itemTypes: ItemType[];
  enabled: boolean;
  createdAt: string;
}

export interface ConnectionDelivery {
  id: string;
  connectionId: string;
  itemType: string;
  itemId: string;
  status: 'success' | 'failed' | 'retrying';
  httpCode?: number;
  error?: string;
  /** 1–4: attempt 1 is immediate, then retries at 60s, 300s, 1800s. */
  attempt: number;
  timestamp: string;
}

export interface ConnectionTestResult {
  success: boolean;
  httpCode?: number;
  error?: string;
}
