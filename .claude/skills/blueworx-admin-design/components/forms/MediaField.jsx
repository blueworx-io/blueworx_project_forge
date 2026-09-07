import React from 'react';
import { Button } from '../core/Button.jsx';
import { Icon } from '../core/Icon.jsx';

export function MediaField({ src, alt = '', hint = 'PNG or SVG, at least 320px wide.', onChoose, onRemove, chooseLabel = 'Choose image', className = '' }) {
  return (
    <div className={`bw-media ${className}`}>
      {src
        ? <img className="bw-media__img" src={src} alt={alt} />
        : <span className="bw-media__empty"><Icon name="image" size={20} /></span>}
      <span className="bw-media__body">
        <span className="bw-media__hint">{hint}</span>
        <span className="bw-media__actions">
          <Button size="sm" icon="upload" onClick={onChoose}>{chooseLabel}</Button>
          {src ? <Button size="sm" variant="link" onClick={onRemove}>Remove</Button> : null}
        </span>
      </span>
    </div>
  );
}
