import React from 'react';

export function RangeField({ value = 50, min = 0, max = 100, step = 1, unit = '', onChange, className = '', ...rest }) {
  return (
    <div className={`bw-range ${className}`}>
      <input type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange && onChange(Number(e.target.value))} {...rest} />
      <span className="bw-range__value">{value}{unit}</span>
    </div>
  );
}
