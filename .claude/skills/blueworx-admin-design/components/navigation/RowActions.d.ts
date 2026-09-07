/**
 * WordPress's pipe-separated row links ("Edit | Duplicate | Trash") under a table row title.
 */
export interface RowAction { id?: string; label: string; href?: string; danger?: boolean; onClick?: () => void }
export interface RowActionsProps {
  actions: RowAction[];
  className?: string;
}
export declare function RowActions(props: RowActionsProps): JSX.Element;
