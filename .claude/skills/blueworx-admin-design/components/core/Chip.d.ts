/**
 * Sentence-case tag for a value the user chose — roles, filters, capabilities.
 */
export interface ChipProps {
  children?: React.ReactNode;
  tone?: 'accent' | 'plain';
  /** Renders a remove affordance when provided. */
  onRemove?: () => void;
  className?: string;
}
export declare function Chip(props: ChipProps): JSX.Element;
