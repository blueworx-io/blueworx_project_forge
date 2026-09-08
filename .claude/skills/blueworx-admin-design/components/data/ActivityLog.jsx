import React from 'react';
import { Icon } from '../core/Icon.jsx';

/** items: [{ id, icon, text, meta }] — order notes, audit trails, sync history. */
export function ActivityLog({ items = [], className = '' }) {
  return (
    <ul className={`bw-activity ${className}`}>
      {items.map((item, i) => (
        <li className="bw-activity__item" key={item.id != null ? item.id : i}>
          <span className="bw-activity__dot"><Icon name={item.icon || 'circle'} size={13} /></span>
          <span className="bw-activity__body">
            <p className="bw-activity__text">{item.text}</p>
            {item.meta ? <p className="bw-activity__meta">{item.meta}</p> : null}
          </span>
        </li>
      ))}
    </ul>
  );
}
