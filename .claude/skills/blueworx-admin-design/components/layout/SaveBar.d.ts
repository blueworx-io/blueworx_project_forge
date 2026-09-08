/**
 * Sticky bottom bar carrying the save state and the committing action.
 */
export interface SaveBarProps {
  /** Unsaved changes exist — enables Save and switches the hint. */
  dirty?: boolean;
  saving?: boolean;
  /** Override the automatic hint text. */
  hint?: string;
  onSave?: () => void;
  onDiscard?: () => void;
  saveLabel?: string;
  /** Extra controls placed before Discard/Save. */
  children?: React.ReactNode;
  className?: string;
}
export declare function SaveBar(props: SaveBarProps): JSX.Element;
