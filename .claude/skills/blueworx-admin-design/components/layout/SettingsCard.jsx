import React from 'react';
import { Button } from '../core/Button.jsx';

/**
 * A settings group as a self-contained panel: title, one-line description,
 * FormRow children, and its own save footer. The unit a plugin settings screen
 * is built from — several stacked in .bw-panels, one per subject.
 */
export function SettingsCard({
  title, description, eyebrow, aside, footer, dirty = false, saving = false,
  onSave, onReset, saveLabel = 'Save', hideFooter = false, children, className = '',
}) {
  return (
    <section className={`bw-card bw-settingscard ${className}`}>
      <div className="bw-card__head">
        <div className="bw-card__titles">
          {eyebrow ? <p className="bw-card__eyebrow">{eyebrow}</p> : null}
          {title ? <h2 className="bw-card__title">{title}</h2> : null}
          {description ? <p className="bw-settingscard__desc">{description}</p> : null}
        </div>
        {aside ? <div className="bw-card__actions">{aside}</div> : null}
      </div>
      <div className="bw-card__body bw-settingscard__body">{children}</div>
      {hideFooter ? null : (
        <div className="bw-card__foot">
          {footer}
          {onReset ? <Button variant="link" onClick={onReset} disabled={!dirty}>Reset</Button> : null}
          <Button variant="primary" onClick={onSave} disabled={saving || (onSave && !dirty)}>
            {saving ? 'Saving…' : saveLabel}
          </Button>
        </div>
      )}
    </section>
  );
}
