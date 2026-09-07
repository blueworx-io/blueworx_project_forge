/**
 * On/off control for a setting that takes effect immediately or on save. 40x22 track, brand indigo when on.
 */
export interface SwitchProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: React.ReactNode;
  /** Second line under the label. */
  help?: string;
  /** Drop the bordered pill wrapper — for switches inside a table cell or row. */
  bare?: boolean;
}
export declare function Switch(props: SwitchProps): JSX.Element;
