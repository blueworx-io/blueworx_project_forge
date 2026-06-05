export type ItemType = 'feature' | 'subitem' | 'bug' | 'feedback' | 'release';

export type WorkflowStage =
  | 'bug-tracking'
  | 'scoping'
  | 'future-idea'
  | 'up-next'
  | 'in-development'
  | 'staging-features'
  | 'active-features';

export type FeaturePrice = 'scoping' | 'premium' | 'teaser' | 'free';
export type BugStatus = 'open' | 'in-progress' | 'resolved';
export type FeedbackStatus = 'open' | 'in-progress' | 'resolved';
export type Priority = 'low' | 'medium' | 'high';

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
}

export interface Release {
  id: string;
  type: 'release';
  name: string;
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
}

export interface CompanyDate {
  id: string;
  title: string;
  date: string;
  description?: string;
  tracked?: boolean;
}

export type Item = Feature | SubItem | Bug | Feedback | Release;

export interface AppSettings {
  parentBrand: string;
  teamMonthlyHours: number;
  brands: string[];
  categories: string[];
}

export interface ArchivedItem {
  id: string;
  itemType: 'feature' | 'subitem' | 'bug' | 'feedback' | 'release' | 'company_date';
  name: string;
  archivedAt: string;
}
