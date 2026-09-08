import React from 'react';

export function Textarea({ mono = false, invalid = false, rows = 4, className = '', ...rest }) {
  return (
    <textarea
      rows={rows}
      aria-invalid={invalid || undefined}
      className={['bw-textarea', mono ? 'bw-textarea--mono' : '', invalid ? 'bw-textarea--invalid' : '', className].filter(Boolean).join(' ')}
      {...rest}
    />
  );
}
