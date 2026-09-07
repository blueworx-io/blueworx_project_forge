/**
 * WordPress's bulk-action control: choose an action, apply it to the checked rows.
 */
export interface BulkActionsProps {
  /** Number of selected rows; 0 disables the control. */
  count: number;
  /** Action labels, or {value,label} objects. */
  actions: Array<string | { value: string; label: string }>;
  value?: string;
  onChange?: (value: string) => void;
  onApply?: () => void;
  className?: string;
}
export declare function BulkActions(props: BulkActionsProps): JSX.Element;
