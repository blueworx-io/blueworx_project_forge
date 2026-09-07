/**
 * Status pill for a record's state — 11px uppercase, never longer than two words.
 */
export interface BadgeProps {
  children?: React.ReactNode;
  tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';
  /** Leading dot, for live/connected style states. */
  dot?: boolean;
  className?: string;
}
export declare function Badge(props: BadgeProps): JSX.Element;
