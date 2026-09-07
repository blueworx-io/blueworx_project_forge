/**
 * A settings group as a self-contained panel — title, description, FormRow children and its
 * own save footer. Use several stacked in `.bw-panels`, one per subject; use plain `Card` when
 * the screen has a single SaveBar instead of per-panel saves.
 */
export interface SettingsCardProps {
  title?: React.ReactNode;
  /** One sentence under the title saying what this group controls. */
  description?: string;
  /** Uppercase kicker above the title. */
  eyebrow?: string;
  /** Header-right content — a Badge, Switch or small Button. */
  aside?: React.ReactNode;
  /** Extra footer content, placed before Reset/Save. */
  footer?: React.ReactNode;
  /** Unsaved changes exist — enables Save and Reset. */
  dirty?: boolean;
  saving?: boolean;
  onSave?: () => void;
  onReset?: () => void;
  saveLabel?: string;
  /** Drop the footer entirely, for a read-only or SaveBar-driven group. */
  hideFooter?: boolean;
  children?: React.ReactNode;
  className?: string;
}
export declare function SettingsCard(props: SettingsCardProps): JSX.Element;
