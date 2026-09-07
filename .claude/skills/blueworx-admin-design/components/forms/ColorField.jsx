import React from 'react';
import { Input } from './Input.jsx';

const DEFAULT_PRESETS = ['#4F46E5', '#0A0C29', '#00A32A', '#DBA617', '#D63638', '#2271B1'];

export function ColorField({ value = '#4F46E5', presets = DEFAULT_PRESETS, onChange, className = '' }) {
  const set = (v) => onChange && onChange(v);
  return (
    <div className={`bw-colorfield ${className}`}>
      <input type="color" className="bw-colorfield__swatch" value={value} aria-label="Colour" onChange={(e) => set(e.target.value)} />
      <span className="bw-colorfield__hex"><Input mono value={value} onChange={(e) => set(e.target.value)} aria-label="Hex value" /></span>
      {presets.length ? (
        <span className="bw-colorfield__presets">
          {presets.map((p) => (
            <button
              key={p}
              type="button"
              title={p}
              aria-label={p}
              className={`bw-colorfield__preset ${p.toLowerCase() === String(value).toLowerCase() ? 'is-active' : ''}`}
              style={{ background: p }}
              onClick={() => set(p)}
            />
          ))}
        </span>
      ) : null}
    </div>
  );
}
