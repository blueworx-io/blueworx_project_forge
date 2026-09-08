import React from 'react';
import { PageHeader } from '../../components/layout/PageHeader.jsx';
import { Card } from '../../components/layout/Card.jsx';
import { Button } from '../../components/core/Button.jsx';
import { Badge } from '../../components/core/Badge.jsx';
import { Icon } from '../../components/core/Icon.jsx';
import { Notice } from '../../components/feedback/Notice.jsx';
import { ProgressBar } from '../../components/feedback/ProgressBar.jsx';
import { StatCard } from '../../components/data/StatCard.jsx';
import { DataTable } from '../../components/data/DataTable.jsx';

const RECENT = [
  { id: 'o-1041', ref: <><span className="bw-table__primary">#1041</span><span className="bw-table__sub">Priya Raman</span></>, plan: 'Pro annual', status: <Badge tone="success">Paid</Badge>, total: '£180.00' },
  { id: 'o-1040', ref: <><span className="bw-table__primary">#1040</span><span className="bw-table__sub">Tom Okafor</span></>, plan: 'Monthly', status: <Badge tone="warning">Retrying</Badge>, total: '£18.00' },
  { id: 'o-1039', ref: <><span className="bw-table__primary">#1039</span><span className="bw-table__sub">Ellie Vance</span></>, plan: 'Pro annual', status: <Badge tone="success">Paid</Badge>, total: '£180.00' },
  { id: 'o-1038', ref: <><span className="bw-table__primary">#1038</span><span className="bw-table__sub">Marcus Bell</span></>, plan: 'Starter', status: <Badge tone="neutral" dot>Refunded</Badge>, total: '£0.00' },
];

const CHECKLIST = [
  { label: 'Connect a payment provider', done: true },
  { label: 'Create your first plan', done: true },
  { label: 'Publish the members page', done: true },
  { label: 'Set who can see reports', done: false },
  { label: 'Send the welcome email', done: false },
];

export function DashboardScreen({ onNavigate }) {
  return (
    <>
      <PageHeader
        eyebrow="Example Plugin"
        title="Overview"
        lede="Members, payments and setup for this site."
        actions={<><Button icon="external-link">View members page</Button><Button variant="primary" icon="plus">New plan</Button></>}
      />
      <div className="wp-notice-slot">
        <Notice tone="warning" title="Test mode is on" actions={<Button size="sm" variant="link" onClick={() => onNavigate && onNavigate('settings')}>Go to payment settings</Button>}>
          Nothing is charged while test mode is on. Switch it off when you are ready to take real payments.
        </Notice>
      </div>
      <div className="bw-page__body" style={{ paddingBottom: 24 }}>
        <div className="bw-panels">
          <div className="bw-stats">
            <StatCard label="Revenue" value="£12,480" icon="chart-column" delta="+12.4%" footnote="vs previous 30 days" />
            <StatCard label="Active members" value="318" icon="users" delta="+9" footnote="new this month" />
            <StatCard label="Failed payments" value="3" icon="triangle-alert" delta="-2" direction="down" footnote="needs attention" />
            <StatCard label="Renewals due" value="27" icon="calendar" footnote="in the next 14 days" />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,2fr) minmax(0,1fr)', gap: 'var(--bw-stack-gap)', alignItems: 'start' }}>
            <Card flush title="Recent orders" actions={<Button size="sm" onClick={() => onNavigate && onNavigate('members')}>View all</Button>}>
              <DataTable
                columns={[{ key: 'ref', label: 'Order' }, { key: 'plan', label: 'Plan' }, { key: 'status', label: 'Status' }, { key: 'total', label: 'Total', align: 'right' }]}
                rows={RECENT}
              />
            </Card>
            <Card title="Setup" eyebrow="3 of 5 done">
              <ProgressBar value={60} label="Setup complete" showPct />
              <ul style={{ listStyle: 'none', margin: '18px 0 0', padding: 0, display: 'flex', flexDirection: 'column', gap: 10 }}>
                {CHECKLIST.map((c) => (
                  <li key={c.label} style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13, color: c.done ? 'var(--bw-text-muted)' : 'var(--bw-text-body)' }}>
                    <Icon name={c.done ? 'circle-check' : 'circle'} size={16} color={c.done ? 'var(--bw-success-deep)' : 'var(--bw-text-faint)'} />
                    <span style={{ textDecoration: c.done ? 'line-through' : 'none' }}>{c.label}</span>
                  </li>
                ))}
              </ul>
              <div style={{ marginTop: 18 }}>
                <Button block variant="secondary" iconRight="arrow-right" onClick={() => onNavigate && onNavigate('setup')}>Finish setup</Button>
              </div>
            </Card>
          </div>
        </div>
      </div>
    </>
  );
}
