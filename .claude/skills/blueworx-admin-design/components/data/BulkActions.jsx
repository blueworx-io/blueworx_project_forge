import React from 'react';
import { Select } from '../forms/Select.jsx';
import { Button } from '../core/Button.jsx';

/** The WordPress bulk-action control: pick an action, apply it to the selection. */
export function BulkActions({ count = 0, actions = [], value = '', onChange, onApply, className = '' }) {
  return (
    <div className={`bw-bulk ${className}`}>
      <span className="bw-bulk__select">
        <Select placeholder="Bulk actions" options={actions} value={value} onChange={(e) => onChange && onChange(e.target.value)} disabled={!count} />
      </span>
      <Button size="sm" disabled={!count || !value} onClick={onApply}>Apply</Button>
      <span className="bw-bulk__count">{count ? `${count} selected` : 'Nothing selected'}</span>
    </div>
  );
}
