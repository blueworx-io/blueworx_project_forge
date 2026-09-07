/**
 * Radio with label and optional help line; wrap a set in RadioGroup.
 */
export interface RadioProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: React.ReactNode;
  help?: string;
}
export interface RadioGroupProps {
  /** Lay the options out in a row instead of a stack. */
  row?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function Radio(props: RadioProps): JSX.Element;
export declare function RadioGroup(props: RadioGroupProps): JSX.Element;
