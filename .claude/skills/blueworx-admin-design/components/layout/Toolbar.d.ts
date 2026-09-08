/**
 * The filter/search strip above a table. Sits directly inside a flush Card, above the DataTable.
 */
export interface ToolbarProps {
  /** Left-hand controls: search, filter selects, bulk actions. */
  children?: React.ReactNode;
  /** Right-hand controls, pushed to the far edge. */
  right?: React.ReactNode;
  /** Drop the bottom hairline — when the toolbar is inside a padded Card body. */
  inCard?: boolean;
  className?: string;
}
export declare function Toolbar(props: ToolbarProps): JSX.Element;
