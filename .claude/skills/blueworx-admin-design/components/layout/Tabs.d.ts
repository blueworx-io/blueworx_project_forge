/**
 * Uppercase underline tabs — the top-level switch between views of one screen.
 */
export interface TabItem { id: string; label: string; count?: number }
export interface TabsProps {
  items: (string | TabItem)[];
  value?: string;
  onChange?: (id: string) => void;
  /** Drop the page gutter and white background — for tabs inside a Card. */
  inset?: boolean;
  className?: string;
}
export declare function Tabs(props: TabsProps): JSX.Element;
