/**
 * Collapsible panel — the BlueWorx take on the WordPress postbox. For advanced or optional settings.
 */
export interface AccordionProps {
  title: React.ReactNode;
  /** Second line in the header, muted. */
  subtitle?: string;
  /** Node rendered before the chevron — a Badge or count. */
  aside?: React.ReactNode;
  defaultOpen?: boolean;
  /** Controlled open state; omit to let the component manage it. */
  open?: boolean;
  onToggle?: (open: boolean) => void;
  children?: React.ReactNode;
  className?: string;
}
export declare function Accordion(props: AccordionProps): JSX.Element;
