/**
 * One headline figure on a plugin dashboard.
 */
export interface StatCardProps {
  label: string;
  value: React.ReactNode;
  /** Lucide icon name shown before the label. */
  icon?: string;
  /** Change indicator, e.g. "+12.4%". */
  delta?: string;
  direction?: 'up' | 'down';
  /** Comparison period or qualifier. */
  footnote?: string;
  className?: string;
}
export declare function StatCard(props: StatCardProps): JSX.Element;
