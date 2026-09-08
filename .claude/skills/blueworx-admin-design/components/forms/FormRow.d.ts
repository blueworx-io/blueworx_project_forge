/**
 * Label left, control right — the shape WordPress developers know from .form-table, restyled.
 * Use inside a Card for dense settings; use Field + .bw-fields for two-column forms.
 */
export interface FormRowProps {
  label: React.ReactNode;
  htmlFor?: string;
  /** One line under the control. */
  help?: string;
  /** Text for a "?" bubble beside the label. */
  tip?: string;
  required?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function FormRow(props: FormRowProps): JSX.Element;
