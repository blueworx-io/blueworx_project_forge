/**
 * Drop target for a data file — CSV imports, JSON settings restores. For images use MediaField.
 */
export interface UploadFieldProps {
  title?: string;
  /** Accepted formats and size, one line. */
  hint?: string;
  /** Filename of the chosen file; switches the row into its filled state. */
  file?: string;
  accept?: string;
  buttonLabel?: string;
  onChoose?: () => void;
  onRemove?: () => void;
  className?: string;
}
export declare function UploadField(props: UploadFieldProps): JSX.Element;
