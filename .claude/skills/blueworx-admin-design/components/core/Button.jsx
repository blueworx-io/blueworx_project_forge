import React from 'react';
import { Icon } from './Icon.jsx';

export function Button({
  children, variant = 'secondary', size = 'md', icon, iconRight, block = false,
  disabled = false, href, type = 'button', className = '', ...rest
}) {
  const cls = [
    'bw-btn', `bw-btn--${variant}`,
    size !== 'md' ? `bw-btn--${size}` : '',
    block ? 'bw-btn--block' : '', className,
  ].filter(Boolean).join(' ');
  const inner = (
    <>
      {icon ? <Icon name={icon} size={size === 'sm' ? 14 : 16} /> : null}
      {children}
      {iconRight ? <Icon name={iconRight} size={size === 'sm' ? 14 : 16} /> : null}
    </>
  );
  if (href) return <a className={cls} href={href} aria-disabled={disabled || undefined} {...rest}>{inner}</a>;
  return <button className={cls} type={type} disabled={disabled} {...rest}>{inner}</button>;
}
