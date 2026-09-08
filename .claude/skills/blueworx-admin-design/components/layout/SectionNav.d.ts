/**
 * Left-hand pill nav for the sections of a long settings screen.
 */
export interface SectionNavItem { id: string; label: string; meta?: React.ReactNode }
export interface SectionNavProps {
  items: (string | SectionNavItem)[];
  /** Active item id. */
  value?: string;
  onChange?: (id: string) => void;
  className?: string;
}
export declare function SectionNav(props: SectionNavProps): JSX.Element;
