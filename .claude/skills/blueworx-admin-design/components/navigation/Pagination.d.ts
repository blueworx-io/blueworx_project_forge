/**
 * Page controls for a DataTable — the BlueWorx replacement for WordPress tablenav paging.
 */
export interface PaginationProps {
  page: number;
  totalPages: number;
  /** Total record count, shown on the left as "1,204 items". */
  totalItems?: number;
  onChange?: (page: number) => void;
  /** Show "3 of 12" instead of numbered buttons — for narrow toolbars. */
  compact?: boolean;
  className?: string;
}
export declare function Pagination(props: PaginationProps): JSX.Element;
