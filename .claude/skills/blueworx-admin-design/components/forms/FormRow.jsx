import React from 'react';
import { HelpTip } from '../feedback/HelpTip.jsx';

/** Label left, control right — the WordPress .form-table shape, restyled. */
export function FormRow({ label, htmlFor, help, tip, required = false, children, className = '' }) {
  return (
    <div className={`bw-formrow ${className}`}>
      <label className="bw-formrow__label" htmlFor={htmlFor}>
        {label}{required ? <span className="bw-formrow__req">*</span> : null}
        {tip ? <HelpTip>{tip}</HelpTip> : null}
      </label>
      <div className="bw-formrow__control">
        {children}
        {help ? <p className="bw-formrow__help">{help}</p> : null}
      </div>
    </div>
  );
}
