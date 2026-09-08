/**
 * Inline message about the screen or an action's result — the BlueWorx take on a wp-admin notice.
 */
export interface NoticeProps {
  tone?: 'info' | 'success' | 'warning' | 'danger' | 'accent';
  title?: React.ReactNode;
  children?: React.ReactNode;
  /** Buttons under the text — usually a link button. */
  actions?: React.ReactNode;
  /** Override the tone's default Lucide icon. */
  icon?: string;
  onDismiss?: () => void;
  className?: string;
}
export declare function Notice(props: NoticeProps): JSX.Element;
