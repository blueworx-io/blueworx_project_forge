import React from 'react';
import { lucideIcons, resolveIconName, lucideStrokeWidth } from '../../assets/icons/lucide-icons.js';

/** A single Lucide icon, rendered inline as SVG. `name` is the Lucide name, e.g. "settings". */
export function Icon({ name, size = 16, strokeWidth = lucideStrokeWidth, color, label, className = '', style }) {
  const table = lucideIcons || (typeof window !== 'undefined' && window.BWLucide ? window.BWLucide.icons : null);
  const key = table ? resolveIconName(name) : '';
  if (!key || !table[key]) {
    if (typeof console !== 'undefined') console.warn('[bw-icon] unknown Lucide icon: ' + name);
    return null;
  }
  return (
    <span
      className={`bw-icon ${className}`}
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
      style={{ width: size, height: size, color, ...style }}
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="100%"
        height="100%"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={strokeWidth}
        strokeLinecap="round"
        strokeLinejoin="round"
        focusable="false"
        dangerouslySetInnerHTML={{ __html: table[key] }}
      />
    </span>
  );
}
