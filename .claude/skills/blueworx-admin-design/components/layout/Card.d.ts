/**
 * The panel every group of settings, tables and content sits in. White, 1px border, 12px radius.
 */
export interface CardProps {
  title?: React.ReactNode;
  /** Uppercase kicker above the title. */
  eyebrow?: string;
  /** Header-right controls. */
  actions?: React.ReactNode;
  /** Explanatory line at the top of the body. */
  note?: string;
  /** Right-aligned footer bar on a sunken background. */
  footer?: React.ReactNode;
  /** Remove body padding — for tables that should meet the card edges. */
  flush?: boolean;
  /** Grey body instead of white, for secondary/nested panels. */
  sunken?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function Card(props: CardProps): JSX.Element;
