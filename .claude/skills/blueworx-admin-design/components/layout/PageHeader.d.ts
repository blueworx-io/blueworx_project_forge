/**
 * The top of every BlueWorx admin screen: eyebrow, Sora title, lede and the screen's actions.
 */
export interface PageHeaderProps {
  /** 11px uppercase kicker — the plugin or area name, e.g. "Example Plugin". */
  eyebrow?: string;
  title: React.ReactNode;
  /** One sentence saying what this screen is for. */
  lede?: string;
  /** Buttons, right-aligned and bottom-aligned to the title. */
  actions?: React.ReactNode;
  /** Extra content under the title — role chips, meta, breadcrumb. */
  children?: React.ReactNode;
  className?: string;
}
export declare function PageHeader(props: PageHeaderProps): JSX.Element;
