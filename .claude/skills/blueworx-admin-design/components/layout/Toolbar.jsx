import React from 'react';

export function Toolbar({ children, right, inCard = false, className = '' }) {
  return (
    <div className={['bw-toolbar', inCard ? 'bw-toolbar--card' : '', className].filter(Boolean).join(' ')}>
      {children}
      {right ? <><span className="bw-toolbar__spacer" /><span className="bw-toolbar__group">{right}</span></> : null}
    </div>
  );
}
