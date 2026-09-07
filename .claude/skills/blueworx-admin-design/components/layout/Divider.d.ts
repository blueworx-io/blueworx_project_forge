/**
 * Hairline between groups inside one panel. With a label, an uppercase section rule.
 */
export interface DividerProps {
  /** Uppercase text centred on the rule. */
  label?: string;
  /** Halve the vertical margin. */
  tight?: boolean;
  className?: string;
}
export declare function Divider(props: DividerProps): JSX.Element;
