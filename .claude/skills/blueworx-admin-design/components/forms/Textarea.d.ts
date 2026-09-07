/**
 * Multi-line text control; vertical resize only.
 */
export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  /** Monospace — for shortcodes, snippets and templates. */
  mono?: boolean;
  invalid?: boolean;
}
export declare function Textarea(props: TextareaProps): JSX.Element;
