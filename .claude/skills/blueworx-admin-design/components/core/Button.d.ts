/**
 * The standard BlueWorx admin action. One primary button per screen region.
 */
export interface ButtonProps {
  children?: React.ReactNode;
  /** primary = the one committing action; secondary = default; ghost = toolbar; danger = destructive; link = inline text action. */
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'link';
  size?: 'sm' | 'md' | 'lg';
  /** Lucide icon name rendered before the label. */
  icon?: string;
  /** Lucide icon name rendered after the label (chevrons, external links). */
  iconRight?: string;
  /** Stretch to the container width. */
  block?: boolean;
  disabled?: boolean;
  /** Render as an anchor instead of a button. */
  href?: string;
  type?: 'button' | 'submit' | 'reset';
  onClick?: (e: React.MouseEvent) => void;
  className?: string;
}
export declare function Button(props: ButtonProps): JSX.Element;
