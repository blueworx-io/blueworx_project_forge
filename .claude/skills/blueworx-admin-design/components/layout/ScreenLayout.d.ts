/**
 * Main column plus a sticky right sidebar — the WordPress edit-screen shape.
 */
export interface ScreenLayoutProps {
  /** Sidebar panels; omit for a single full-width column. */
  sidebar?: React.ReactNode;
  /** 260px sidebar instead of 300px. */
  narrowSidebar?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function ScreenLayout(props: ScreenLayoutProps): JSX.Element;
