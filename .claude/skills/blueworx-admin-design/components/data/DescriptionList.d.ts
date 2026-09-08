/**
 * Read-only term/value pairs — a record's details in a drawer, modal or sidebar panel.
 */
export interface DescriptionListProps {
  /** [term, value] pairs or { term, value } objects. */
  items: Array<[React.ReactNode, React.ReactNode] | { term: React.ReactNode; value: React.ReactNode }>;
  /** Stack term above value with an uppercase term — for narrow sidebars. */
  stack?: boolean;
  className?: string;
}
export declare function DescriptionList(props: DescriptionListProps): JSX.Element;
