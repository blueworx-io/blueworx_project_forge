/**
 * Read-only value with a copy button — shortcodes, API keys, webhook URLs, licence keys.
 */
export interface CopyFieldProps {
  value: string;
  /** Monospace, on by default. */
  mono?: boolean;
  /** Button label before the copy happens. */
  label?: string;
  className?: string;
}
export declare function CopyField(props: CopyFieldProps): JSX.Element;
