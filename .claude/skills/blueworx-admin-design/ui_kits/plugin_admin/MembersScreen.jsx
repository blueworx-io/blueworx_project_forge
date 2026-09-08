import React from 'react';
import { PageHeader } from '../../components/layout/PageHeader.jsx';
import { Tabs } from '../../components/layout/Tabs.jsx';
import { Card } from '../../components/layout/Card.jsx';
import { Button } from '../../components/core/Button.jsx';
import { IconButton } from '../../components/core/IconButton.jsx';
import { Badge } from '../../components/core/Badge.jsx';
import { Chip } from '../../components/core/Chip.jsx';
import { Input } from '../../components/forms/Input.jsx';
import { Select } from '../../components/forms/Select.jsx';
import { DataTable } from '../../components/data/DataTable.jsx';
import { EmptyState } from '../../components/feedback/EmptyState.jsx';
import { Modal } from '../../components/feedback/Modal.jsx';

const MEMBERS = [
  { id: 'm1', name: 'Priya Raman', email: 'priya@example.com', plan: 'Pro annual · £180/yr', joined: '12 Mar 2026', spend: '£540', state: 'active' },
  { id: 'm2', name: 'Tom Okafor', email: 'tom@example.com', plan: 'Monthly · £18/mo', joined: '02 Jun 2026', spend: '£126', state: 'failed' },
  { id: 'm3', name: 'Ellie Vance', email: 'ellie@example.com', plan: 'Pro annual · £180/yr', joined: '21 Jan 2026', spend: '£180', state: 'paused' },
  { id: 'm4', name: 'Marcus Bell', email: 'marcus@example.com', plan: 'Starter · £60/yr', joined: '30 Jul 2026', spend: '£60', state: 'active' },
  { id: 'm5', name: 'Sofia Duarte', email: 'sofia@example.com', plan: 'Monthly · £18/mo', joined: '14 Aug 2026', spend: '£54', state: 'active' },
];

const STATE_BADGE = {
  active: <Badge tone="success">Active</Badge>,
  failed: <Badge tone="warning">Payment failed</Badge>,
  paused: <Badge tone="neutral" dot>Paused</Badge>,
};

export function MembersScreen() {
  const [tab, setTab] = React.useState('all');
  const [query, setQuery] = React.useState('');
  const [selected, setSelected] = React.useState([]);
  const [open, setOpen] = React.useState(null);

  const filtered = MEMBERS.filter((m) => (tab === 'all' || m.state === tab))
    .filter((m) => (m.name + m.email).toLowerCase().includes(query.toLowerCase()));

  const rows = filtered.map((m) => ({
    id: m.id,
    name: <><span className="bw-table__primary">{m.name}</span><span className="bw-table__sub">{m.email}</span></>,
    plan: m.plan,
    status: STATE_BADGE[m.state],
    joined: m.joined,
    spend: m.spend,
  }));

  return (
    <>
      <PageHeader
        eyebrow="Example Plugin"
        title="Members"
        lede="Everyone with access to the members area."
        actions={<><Button icon="download">Export CSV</Button><Button variant="primary" icon="plus">Add member</Button></>}
      />
      <Tabs
        value={tab}
        onChange={(t) => { setTab(t); setSelected([]); }}
        items={[
          { id: 'all', label: 'All', count: MEMBERS.length },
          { id: 'active', label: 'Active', count: MEMBERS.filter((m) => m.state === 'active').length },
          { id: 'failed', label: 'Payment failed', count: MEMBERS.filter((m) => m.state === 'failed').length },
          { id: 'paused', label: 'Paused', count: MEMBERS.filter((m) => m.state === 'paused').length },
        ]}
      />
      <div className="bw-page__body" style={{ paddingBottom: 24 }}>
        <div className="bw-panels">
          <Card flush>
            <div className="bw-toolbar">
              <span className="bw-toolbar__search">
                <Input icon="search" size="sm" placeholder="Search members" value={query} onChange={(e) => setQuery(e.target.value)} />
              </span>
              <span style={{ width: 150 }}><Select placeholder="All plans" options={['Pro annual', 'Monthly', 'Starter']} /></span>
              <span className="bw-toolbar__spacer" />
              {selected.length ? (
                <>
                  <span style={{ fontSize: 12, color: 'var(--bw-text-muted)' }}>{selected.length} selected</span>
                  <Button size="sm" icon="mail">Email</Button>
                  <Button size="sm" variant="danger" icon="trash-2">Remove</Button>
                </>
              ) : (
                <span style={{ fontSize: 12, color: 'var(--bw-text-muted)' }}>{filtered.length} of {MEMBERS.length} members</span>
              )}
            </div>
            <DataTable
              columns={[
                { key: 'name', label: 'Member' },
                { key: 'plan', label: 'Plan' },
                { key: 'status', label: 'Status' },
                { key: 'joined', label: 'Joined' },
                { key: 'spend', label: 'Lifetime', align: 'right' },
              ]}
              rows={rows}
              selectable
              selected={selected}
              onToggle={(id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))}
              onToggleAll={(checked) => setSelected(checked ? rows.map((r) => r.id) : [])}
              rowActions={(row) => (
                <>
                  <IconButton icon="eye" label="View member" size="sm" onClick={() => setOpen(MEMBERS.find((m) => m.id === row.id))} />
                  <IconButton icon="mail" label="Email member" size="sm" />
                  <IconButton icon="trash-2" label="Remove member" size="sm" variant="danger" />
                </>
              )}
              empty={<div style={{ padding: 24 }}><EmptyState icon="users" title="No members match that search" actions={<Button size="sm" onClick={() => { setQuery(''); setTab('all'); }}>Clear filters</Button>}>Try a different name, email or plan.</EmptyState></div>}
            />
            {rows.length ? <div className="bw-tablefoot"><span>{rows.length} of {MEMBERS.length}</span><span>Page 1 of 1</span></div> : null}
          </Card>
        </div>
      </div>

      <Modal
        open={!!open}
        title={open ? open.name : ''}
        subtitle={open ? open.email : ''}
        onClose={() => setOpen(null)}
        footer={<><Button onClick={() => setOpen(null)}>Close</Button><Button variant="primary" icon="pencil">Edit member</Button></>}
      >
        {open ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div className="bw-chips">
              {STATE_BADGE[open.state]}
              <Chip>Member since {open.joined}</Chip>
              <Chip tone="plain">Lifetime {open.spend}</Chip>
            </div>
            <div className="bw-fields">
              <div className="bw-field"><span className="bw-field__label">Plan</span><span>{open.plan}</span></div>
              <div className="bw-field"><span className="bw-field__label">Next renewal</span><span>01 Sep 2026</span></div>
              <div className="bw-field"><span className="bw-field__label">Payment method</span><span>Visa ···· 4242</span></div>
              <div className="bw-field"><span className="bw-field__label">Role</span><span>Subscriber</span></div>
            </div>
          </div>
        ) : null}
      </Modal>
    </>
  );
}
