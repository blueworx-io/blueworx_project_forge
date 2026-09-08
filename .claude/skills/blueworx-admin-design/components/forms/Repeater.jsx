import React from 'react';
import { Icon } from '../core/Icon.jsx';
import { Button } from '../core/Button.jsx';
import { IconButton } from '../core/IconButton.jsx';

/** Repeatable rows of fields — plans, redirects, custom fields, menu items. */
export function Repeater({ items = [], renderRow, onAdd, onRemove, addLabel = 'Add row', emptyLabel = 'Nothing here yet.', reorderable = true, footer, className = '' }) {
  return (
    <div className={`bw-repeater ${className}`}>
      {items.length === 0 ? <p className="bw-repeater__empty">{emptyLabel}</p> : null}
      {items.map((item, i) => (
        <div className="bw-repeater__row" key={item.id != null ? item.id : i}>
          <div className="bw-repeater__bar">
            {reorderable ? <Icon name="grip-vertical" size={16} className="bw-repeater__grip" label="Drag to reorder" /> : null}
            <IconButton icon="trash-2" label="Remove row" size="sm" variant="danger" onClick={() => onRemove && onRemove(item.id != null ? item.id : i)} />
          </div>
          <div className="bw-repeater__fields">{renderRow ? renderRow(item, i) : null}</div>
        </div>
      ))}
      <div className="bw-repeater__foot">
        <Button size="sm" icon="plus" onClick={onAdd}>{addLabel}</Button>
        {footer}
      </div>
    </div>
  );
}
