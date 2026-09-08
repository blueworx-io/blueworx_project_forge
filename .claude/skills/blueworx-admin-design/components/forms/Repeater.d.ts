/**
 * Repeatable rows of fields — plans, redirects, custom fields, opening hours.
 */
export interface RepeaterProps {
  /** Each item should carry an `id`. */
  items: Array<{ id?: string | number; [key: string]: any }>;
  /** Renders the controls for one row; each child flexes to fill. */
  renderRow?: (item: any, index: number) => React.ReactNode;
  onAdd?: () => void;
  onRemove?: (id: string | number) => void;
  addLabel?: string;
  /** Shown in place of rows when the list is empty. */
  emptyLabel?: string;
  /** Show the drag grip. */
  reorderable?: boolean;
  /** Extra content on the right of the add row — a count or hint. */
  footer?: React.ReactNode;
  className?: string;
}
export declare function Repeater(props: RepeaterProps): JSX.Element;
