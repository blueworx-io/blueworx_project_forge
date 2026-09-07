/**
 * The small "?" bubble beside a setting's label, for the sentence that will not fit in help text.
 */
export interface HelpTipProps {
  children?: React.ReactNode;
  /** Open below instead of above — for tips near the top of the screen. */
  below?: boolean;
  className?: string;
}
export declare function HelpTip(props: HelpTipProps): JSX.Element;
