/**
 * Numbered steps for a setup or import flow; completed steps get a tick.
 */
export interface StepItem { id: string; label: string }
export interface StepperProps {
  steps: (string | StepItem)[];
  /** Id of the current step; everything before it renders as done. */
  current: string;
  onChange?: (id: string) => void;
  className?: string;
}
export declare function Stepper(props: StepperProps): JSX.Element;
