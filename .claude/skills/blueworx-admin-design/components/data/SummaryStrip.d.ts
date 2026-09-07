/**
 * One figure in the summary strip. Every value is derived — the strip shows
 * what the screen's values add up to, and saves nothing of its own.
 */
export interface SummaryCell {
  label: string;
  value: string;
  /** Faint line under the figure, e.g. "18 line items". */
  foot?: string;
}

export interface SummaryStripProps {
  cells?: SummaryCell[];
  className?: string;
}
export declare function SummaryStrip(props: SummaryStripProps): JSX.Element | null;
