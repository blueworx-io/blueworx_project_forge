import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function Stepper({ steps = [], current, onChange, className = '' }) {
  const currentIndex = steps.findIndex((s) => (typeof s === 'string' ? s : s.id) === current);
  return (
    <nav className={`bw-steps ${className}`} aria-label="Progress">
      {steps.map((raw, i) => {
        const step = typeof raw === 'string' ? { id: raw, label: raw } : raw;
        const done = i < currentIndex;
        const isCurrent = i === currentIndex;
        return (
          <React.Fragment key={step.id}>
            {i > 0 ? <span className="bw-steps__sep" aria-hidden="true" /> : null}
            <button
              type="button"
              className={`bw-step ${isCurrent ? 'is-current' : ''} ${done ? 'is-done' : ''}`}
              aria-current={isCurrent ? 'step' : undefined}
              onClick={() => onChange && onChange(step.id)}
            >
              <span className="bw-step__n">{done ? <Icon name="check" size={12} /> : i + 1}</span>
              {step.label}
            </button>
          </React.Fragment>
        );
      })}
    </nav>
  );
}
