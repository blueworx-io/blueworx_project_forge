/**
 * Square icon-only action for table rows, card headers and toolbars. Always labelled.
 */
export interface IconButtonProps {
  /** Lucide icon name. */
  icon: string;
  /** Required — becomes both the tooltip and the accessible name. */
  label: string;
  variant?: 'ghost' | 'outline' | 'danger';
  size?: 'sm' | 'md';
  disabled?: boolean;
  onClick?: (e: React.MouseEvent) => void;
  className?: string;
}
export declare function IconButton(props: IconButtonProps): JSX.Element;
