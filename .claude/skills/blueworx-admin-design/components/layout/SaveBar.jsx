import React from 'react';
import { Button } from '../core/Button.jsx';
import { Icon } from '../core/Icon.jsx';

export function SaveBar({ dirty = false, saving = false, hint, onSave, onDiscard, saveLabel = 'Save changes', children, className = '' }) {
  const message = hint || (dirty ? 'You have unsaved changes.' : 'All changes saved.');
  return (
    <div className={`bw-savebar ${className}`}>
      <p className="bw-savebar__hint">
        <Icon name={dirty ? 'info' : 'circle-check'} size={16} color={dirty ? 'var(--bw-warning-deep)' : 'var(--bw-success-deep)'} />
        {message}
      </p>
      {children}
      {onDiscard ? <Button variant="link" onClick={onDiscard} disabled={!dirty}>Discard</Button> : null}
      <Button variant="primary" onClick={onSave} disabled={saving || !dirty}>{saving ? 'Saving…' : saveLabel}</Button>
    </div>
  );
}
