/**
 * Right-hand slide-over for a record's detail or a secondary form, without leaving the list.
 */
export interface DrawerProps {
  open?: boolean;
  title?: React.ReactNode;
  subtitle?: string;
  footer?: React.ReactNode;
  /** 640px instead of 460px. */
  wide?: boolean;
  onClose?: () => void;
  children?: React.ReactNode;
  className?: string;
}
export declare function Drawer(props: DrawerProps): JSX.Element;
