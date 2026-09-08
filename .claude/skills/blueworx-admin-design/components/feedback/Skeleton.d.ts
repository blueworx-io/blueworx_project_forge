/**
 * Placeholder shape while a panel's data loads. Prefer it over a centred spinner for content areas.
 */
export interface SkeletonProps {
  variant?: 'text' | 'title' | 'block' | 'circle';
  width?: number | string;
  height?: number | string;
  /** For variant="text": number of lines; the last one is shortened. */
  lines?: number;
  className?: string;
}
export declare function Skeleton(props: SkeletonProps): JSX.Element;
