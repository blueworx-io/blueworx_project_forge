/**
 * Centred dialog for a confirmation or a short focused form.
 */
export interface ModalProps {
  open?: boolean;
  title?: React.ReactNode;
  subtitle?: string;
  /** Right-aligned action row. */
  footer?: React.ReactNode;
  /** 760px instead of 520px. */
  wide?: boolean;
  onClose?: () => void;
  children?: React.ReactNode;
  className?: string;
}
export declare function Modal(props: ModalProps): JSX.Element;
