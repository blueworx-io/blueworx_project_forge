/**
 * Trail back to the parent screen — used when a plugin has detail views under a list.
 */
export interface BreadcrumbItem { id?: string; label: string; href?: string }
export interface BreadcrumbsProps {
  /** Strings or items; the last one renders as the current page and is not a link. */
  items: (string | BreadcrumbItem)[];
  onNavigate?: (id: string) => void;
  className?: string;
}
export declare function Breadcrumbs(props: BreadcrumbsProps): JSX.Element;
