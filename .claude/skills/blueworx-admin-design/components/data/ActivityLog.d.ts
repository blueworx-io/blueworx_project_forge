/**
 * Vertical timeline of what happened to a record — order notes, sync history, audit trail.
 */
export interface ActivityItem {
  id?: string | number;
  /** Lucide icon name for the dot. */
  icon?: string;
  text: React.ReactNode;
  /** Who and when. */
  meta?: string;
}
export interface ActivityLogProps {
  items: ActivityItem[];
  className?: string;
}
export declare function ActivityLog(props: ActivityLogProps): JSX.Element;
