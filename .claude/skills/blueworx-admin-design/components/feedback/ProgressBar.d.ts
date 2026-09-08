/**
 * Completion of a setup flow, import or sync. 0–100.
 */
export interface ProgressBarProps {
  value: number;
  label?: string;
  /** Show the Sora percentage above the track. */
  showPct?: boolean;
  size?: 'sm' | 'md';
  className?: string;
}
export declare function ProgressBar(props: ProgressBarProps): JSX.Element;
