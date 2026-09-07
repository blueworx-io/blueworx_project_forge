import React from 'react';
import { Card } from '../../components/layout/Card.jsx';
import { Button } from '../../components/core/Button.jsx';
import { Icon } from '../../components/core/Icon.jsx';
import { Badge } from '../../components/core/Badge.jsx';

const SUMMARY = [
  ['Site name', 'Example Site'],
  ['Members page', '/members'],
  ['Look', 'Bright'],
  ['Roles with access', 'Administrator, Site manager, Author, Subscriber'],
  ['Payments', 'Not connected yet'],
];

export function StepFinish({ onDone }) {
  return (
    <>
      <Card title="Ready to publish" eyebrow="Step 4" note="Check it over. Nothing is live on the site until you publish."
        actions={<Badge tone="accent">Draft</Badge>}>
        <table className="bw-table">
          <tbody>
            {SUMMARY.map(([k, v]) => (
              <tr key={k}>
                <td style={{ width: 200, color: 'var(--bw-text-muted)' }}>{k}</td>
                <td><span className="bw-table__primary">{v}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
      <Card sunken>
        <div style={{ display: 'flex', gap: 14, alignItems: 'center', flexWrap: 'wrap' }}>
          <Icon name="megaphone" size={20} color="var(--bw-text-accent)" />
          <span style={{ flex: '1 1 260px', fontSize: 13, color: 'var(--bw-text-muted)' }}>
            Connect a payment provider next to start taking memberships.
          </span>
          <Button variant="primary" iconRight="arrow-right" onClick={onDone}>Publish members area</Button>
        </div>
      </Card>
    </>
  );
}
