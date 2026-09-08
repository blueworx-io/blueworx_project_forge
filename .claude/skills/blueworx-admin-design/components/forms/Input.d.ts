/**
 * Single-line text control, 34px tall, 14px text.
 */
export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  /** Lucide icon name shown inside the left edge (search, links, keys). */
  icon?: string;
  /** Short static suffix inside the right edge, e.g. "/mo" or "%". */
  affix?: string;
  /** Monospace — use for keys, shortcodes, IDs and any value with brackets. */
  mono?: boolean;
  invalid?: boolean;
  size?: 'sm' | 'md';
}
export declare function Input(props: InputProps): JSX.Element;
