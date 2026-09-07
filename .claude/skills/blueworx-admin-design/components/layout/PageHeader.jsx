import React from 'react';

export function PageHeader({ eyebrow, title, lede, actions, children, className = '' }) {
  return (
    <header className={`bw-pagehead ${className}`}>
      <div className="bw-pagehead__titles">
        {eyebrow ? <p className="bw-pagehead__eyebrow">{eyebrow}</p> : null}
        <h1 className="bw-pagehead__h1">{title}</h1>
        {lede ? <p className="bw-pagehead__lede">{lede}</p> : null}
        {children}
      </div>
      {actions ? <div className="bw-pagehead__actions">{actions}</div> : null}
    </header>
  );
}
