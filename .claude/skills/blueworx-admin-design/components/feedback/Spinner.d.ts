/**
 * Busy indicator for a control or a panel that is loading.
 */
export interface SpinnerProps {
  size?: number;
  /** Text beside the spinner; also makes it a live region. */
  label?: string;
  className?: string;
}
export declare function Spinner(props: SpinnerProps): JSX.Element;
