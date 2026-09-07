/**
 * Transient confirmation of a background action, bottom-right. For anything the user must read, use Notice.
 */
export interface ToastProps {
  children?: React.ReactNode;
  /** Handler for the inline action, usually Undo. */
  action?: () => void;
  actionLabel?: string;
  onDismiss?: () => void;
  /** Lucide icon name. */
  icon?: string;
  className?: string;
}
export interface ToastStackProps { children?: React.ReactNode; className?: string }
export declare function Toast(props: ToastProps): JSX.Element;
export declare function ToastStack(props: ToastStackProps): JSX.Element;
