/**
 * Image picker matching the WordPress media library flow: preview, choose, remove.
 */
export interface MediaFieldProps {
  /** Current image URL; renders a dashed empty box when absent. */
  src?: string;
  alt?: string;
  /** Size/format guidance, one line. */
  hint?: string;
  onChoose?: () => void;
  onRemove?: () => void;
  chooseLabel?: string;
  className?: string;
}
export declare function MediaField(props: MediaFieldProps): JSX.Element;
