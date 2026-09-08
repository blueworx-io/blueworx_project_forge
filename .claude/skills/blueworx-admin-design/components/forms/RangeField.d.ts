/**
 * Slider with a live numeric read-out, for a bounded number that has a feel to it.
 */
export interface RangeFieldProps {
  value: number;
  min?: number;
  max?: number;
  step?: number;
  /** Suffix shown after the value, e.g. "px" or "%". */
  unit?: string;
  onChange?: (value: number) => void;
  className?: string;
}
export declare function RangeField(props: RangeFieldProps): JSX.Element;
