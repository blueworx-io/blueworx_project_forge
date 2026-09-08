/**
 * What a list, table or panel shows before there is any data.
 */
export interface EmptyStateProps {
  /** Lucide icon name; pick the icon of the thing that's missing. */
  icon?: string;
  title: React.ReactNode;
  children?: React.ReactNode;
  actions?: React.ReactNode;
  className?: string;
}
export declare function EmptyState(props: EmptyStateProps): JSX.Element;
