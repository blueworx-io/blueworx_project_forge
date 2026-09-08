/**
 * Click-triggered menu for overflow actions. Closes on outside click and Escape.
 */
export interface DropdownMenuItem {
  id?: string;
  label?: string;
  /** Lucide icon name. */
  icon?: string;
  danger?: boolean;
  disabled?: boolean;
  onClick?: () => void;
  /** Renders a hairline instead of an item. */
  separator?: boolean;
  /** Renders an uppercase group label instead of an item. */
  heading?: boolean;
}
export interface DropdownMenuProps {
  /** The clickable element — usually an IconButton or Button. */
  trigger: React.ReactNode;
  items: DropdownMenuItem[];
  /** Which edge the panel aligns to. */
  align?: 'left' | 'right';
  onSelect?: (id: string) => void;
  className?: string;
}
export declare function DropdownMenu(props: DropdownMenuProps): JSX.Element;
