/**
 * Label + control + help/error wrapper. Every input on a BlueWorx admin screen sits in one.
 */
export interface FieldProps {
  /** 11px uppercase label. Sentence-case words, no trailing colon. */
  label?: string;
  htmlFor?: string;
  /** One line under the control. Says what the setting does, not how to type. */
  help?: string;
  /** Replaces help when present and turns the message red. */
  error?: string;
  required?: boolean;
  /** Span both columns of a .bw-fields grid. */
  wide?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function Field(props: FieldProps): JSX.Element;
