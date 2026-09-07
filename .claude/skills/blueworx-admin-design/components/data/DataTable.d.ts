/**
 * The BlueWorx replacement for WP_List_Table: uppercase headers, hover rows, actions revealed on hover.
 */
export interface DataTableColumn { key: string; label: React.ReactNode; align?: 'left' | 'right'; width?: number | string }
export interface DataTableRow { id: string | number; [key: string]: any }

/** A named group of rows, e.g. one phase of an estimate. */
export interface DataTableGroup {
  id: string | number;
  title: React.ReactNode;
  rows: DataTableRow[];
  /** Already-formatted subtotal, e.g. "Phase subtotal 48 hrs". The table sums nothing. */
  subtotalLabel?: React.ReactNode;
}

export interface DataTableProps {
  columns: DataTableColumn[];
  /** Each row needs an `id`; other keys match column keys and may hold nodes. */
  rows?: DataTableRow[];
  /** Rows under named group headers. Pass this or `rows`, not both. */
  groups?: DataTableGroup[] | null;
  /** A closing row, e.g. { label: 'Project total', value: '232 hrs' }. */
  total?: { label: React.ReactNode; value?: React.ReactNode } | null;
  /** Adds the leading checkbox column for bulk actions. */
  selectable?: boolean;
  selected?: Array<string | number>;
  onToggle?: (id: string | number) => void;
  onToggleAll?: (checked: boolean) => void;
  /** Render row-level actions; they fade in on row hover. */
  rowActions?: (row: any) => React.ReactNode;
  /** Rendered instead of the table when there are no rows — pass an EmptyState. */
  empty?: React.ReactNode;
  className?: string;
}
export declare function DataTable(props: DataTableProps): JSX.Element;
