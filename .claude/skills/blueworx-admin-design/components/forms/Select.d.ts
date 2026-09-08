/**
 * Native select with the BlueWorx control skin and a Lucide chevron.
 */
export interface SelectOption { value: string; label: string; disabled?: boolean }
export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  /** Strings or {value,label} objects. Omit and pass <option> children instead if you need groups. */
  options?: (string | SelectOption)[];
  /** Empty-value first option, e.g. "All statuses". */
  placeholder?: string;
  invalid?: boolean;
}
export declare function Select(props: SelectProps): JSX.Element;
