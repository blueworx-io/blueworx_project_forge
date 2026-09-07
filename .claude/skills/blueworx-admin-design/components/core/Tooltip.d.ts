/**
 * Short label on hover or focus. Never put an action inside one.
 */
export interface TooltipProps {
  label: React.ReactNode;
  below?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function Tooltip(props: TooltipProps): JSX.Element;
