import React from 'react';
import { Card } from '../../components/layout/Card.jsx';
import { Checkbox } from '../../components/forms/Checkbox.jsx';
import { Switch } from '../../components/forms/Switch.jsx';
import { Notice } from '../../components/feedback/Notice.jsx';

const ROLES = [
  ['Administrator', 'Full access', true, true],
  ['Site manager', 'Members, plans, emails', true, false],
  ['Author', 'Content only', true, false],
  ['Subscriber', 'Their own account', true, false],
  ['Editor', '', false, false],
  ['Contributor', '', false, false],
];

export function StepAccess({ onTouch }) {
  return (
    <>
      <Card title="Who gets in" eyebrow="Step 3" note="Roles you tick here can open the members area. Everything else stays as WordPress has it.">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }}>
          {ROLES.map(([name, help, on, locked]) => (
            <Checkbox key={name} label={name} help={help || undefined} defaultChecked={on} disabled={locked} onChange={onTouch} />
          ))}
        </div>
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 20 }}>
          <Switch label="Hide the members page from search engines" defaultChecked onChange={onTouch} />
          <Switch label="Require email confirmation" onChange={onTouch} />
        </div>
      </Card>
      <Notice tone="info" title="Administrators always have access">The Administrator row is fixed so a site owner cannot lock themselves out.</Notice>
    </>
  );
}
