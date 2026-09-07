/**
 * One phase on the scale. Weeks are authored, never derived from estimated
 * hours — a schedule is what the team can actually do.
 */
export interface GanttPhase {
  id: string | number;
  title: React.ReactNode;
  /** First unit the phase occupies, 1-based. */
  start: number;
  /** Last unit the phase occupies, inclusive. */
  end: number;
  /** Sets the bar's colour. 'launch' is the milestone dividing the two halves. */
  kind?: 'pre' | 'launch' | 'post';
  /** Muted line under the title, e.g. "Weeks 4–7 · Design sign-off". */
  range?: React.ReactNode;
  /** Text inside the bar — a milestone name, or the phase description. */
  label?: React.ReactNode;
  /** False dims the bar: planned, but not shown to the client. */
  visible?: boolean;
}

export interface GanttProps {
  phases?: GanttPhase[];
  /** The scale's full width, in the same unit the phases are numbered in. */
  span?: number;
  /** Ruler labels, spread evenly across the track. */
  ticks?: React.ReactNode[];
  legend?: boolean;
  /** Row-level controls, rendered after the track. */
  actions?: (phase: GanttPhase, index: number) => React.ReactNode;
  className?: string;
}
export declare function Gantt(props: GanttProps): JSX.Element;
