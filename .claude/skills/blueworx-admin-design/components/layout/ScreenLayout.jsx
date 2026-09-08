import React from 'react';

/** Main column + sticky sidebar — the WordPress edit-screen shape. */
export function ScreenLayout({ sidebar, narrowSidebar = false, children, className = '' }) {
  if (!sidebar) return <div className={`bw-panels ${className}`}>{children}</div>;
  return (
    <div className={['bw-screen', narrowSidebar ? 'bw-screen--narrow-side' : '', className].filter(Boolean).join(' ')}>
      <div className="bw-panels">{children}</div>
      <div className="bw-screen__side">{sidebar}</div>
    </div>
  );
}
