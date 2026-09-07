/**
 * A single Lucide icon, rendered inline as SVG.
 */
export interface IconProps {
  /** Lucide name in kebab-case, e.g. "settings", "circle-check". */
  name: string;
  /** Rendered box in px. 16 for inline text, 18–20 for buttons, 22+ for empty states. */
  size?: number;
  /** Stroke weight. Defaults to 2, the same as `lucide-react`. */
  strokeWidth?: number;
  /** CSS colour; defaults to currentColor. */
  color?: string;
  /** Accessible label. Omit for decorative icons — the icon is then aria-hidden. */
  label?: string;
  className?: string;
  style?: React.CSSProperties;
}
export declare function Icon(props: IconProps): JSX.Element;
