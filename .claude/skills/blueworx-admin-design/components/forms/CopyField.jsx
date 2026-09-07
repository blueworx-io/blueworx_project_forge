import React from 'react';
import { Input } from './Input.jsx';
import { Button } from '../core/Button.jsx';

/** Read-only value with a copy button — shortcodes, API keys, webhook URLs. */
export function CopyField({ value = '', mono = true, label = 'Copy', className = '' }) {
  const [copied, setCopied] = React.useState(false);
  const copy = () => {
    if (navigator.clipboard) navigator.clipboard.writeText(value);
    setCopied(true);
    setTimeout(() => setCopied(false), 1600);
  };
  return (
    <div className={`bw-copyfield ${className}`}>
      <Input readOnly mono={mono} value={value} onFocus={(e) => e.target.select()} />
      <Button className="bw-copyfield__btn" icon={copied ? 'check' : 'file'} onClick={copy}>{copied ? 'Copied' : label}</Button>
    </div>
  );
}
