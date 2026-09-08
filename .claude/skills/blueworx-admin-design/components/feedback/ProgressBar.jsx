import React from 'react';

export function ProgressBar({ value = 0, label, showPct = false, size = 'md', className = '' }) {
  const pct = Math.max(0, Math.min(100, Math.round(value)));
  return (
    <div className={['bw-progress', size === 'sm' ? 'bw-progress--sm' : '', className].filter(Boolean).join(' ')}>
      {(label || showPct) ? (
        <div className="bw-progress__row">
          {label ? <span className="bw-progress__label">{label}</span> : <span />}
          {showPct ? <p className="bw-progress__pct">{pct}%</p> : null}
        </div>
      ) : null}
      <div className="bw-progress__track" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
        <div className="bw-progress__bar" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}
