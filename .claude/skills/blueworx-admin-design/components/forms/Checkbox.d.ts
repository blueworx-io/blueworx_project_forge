/**
 * Checkbox with label and optional help line. Uses the brand accent-color.
 */
export interface CheckboxProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: React.ReactNode;
  /** Second line under the label, 12px muted. */
  help?: string;
}
export declare function Checkbox(props: CheckboxProps): JSX.Element;
