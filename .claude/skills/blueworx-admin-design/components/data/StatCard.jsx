import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function StatCard({ label, value, icon, delta, direction = 'up', footnote, className = '' }) {
  return (
    <div className={`bw-stat ${className}`}>
      <span className="bw-stat__label">
        {icon ? <Icon name={icon} size={14} /> : null}
        {label}
      </span>
      <div className="bw-stat__row">
        <p className="bw-stat__value">{value}</p>
        {delta ? <span className={`bw-stat__delta bw-stat__delta--${direction}`}>{delta}</span> : null}
      </div>
      {footnote ? <p className="bw-stat__foot">{footnote}</p> : null}
    </div>
  );
}
