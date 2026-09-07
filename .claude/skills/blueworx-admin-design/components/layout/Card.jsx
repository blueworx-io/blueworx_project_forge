import React from 'react';

export function Card({ title, eyebrow, actions, note, footer, flush = false, sunken = false, children, className = '', ...rest }) {
  return (
    <section className={['bw-card', flush ? 'bw-card--flush' : '', sunken ? 'bw-card--sunken' : '', className].filter(Boolean).join(' ')} {...rest}>
      {(title || actions) ? (
        <div className="bw-card__head">
          <div className="bw-card__titles">
            {eyebrow ? <p className="bw-card__eyebrow">{eyebrow}</p> : null}
            {title ? <h2 className="bw-card__title">{title}</h2> : null}
          </div>
          {actions ? <div className="bw-card__actions">{actions}</div> : null}
        </div>
      ) : null}
      <div className="bw-card__body">
        {note ? <p className="bw-card__note">{note}</p> : null}
        {children}
      </div>
      {footer ? <div className="bw-card__foot">{footer}</div> : null}
    </section>
  );
}
