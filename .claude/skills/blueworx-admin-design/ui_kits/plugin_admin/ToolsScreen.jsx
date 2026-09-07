import React from 'react';
import { PageHeader } from '../../components/layout/PageHeader.jsx';
import { Card } from '../../components/layout/Card.jsx';
import { Button } from '../../components/core/Button.jsx';
import { Badge } from '../../components/core/Badge.jsx';
import { Field } from '../../components/forms/Field.jsx';
import { Input } from '../../components/forms/Input.jsx';
import { Notice } from '../../components/feedback/Notice.jsx';
import { Modal } from '../../components/feedback/Modal.jsx';
import { ProgressBar } from '../../components/feedback/ProgressBar.jsx';

export function ToolsScreen() {
  const [confirm, setConfirm] = React.useState(false);
  const [importing, setImporting] = React.useState(0);

  const runImport = () => {
    setImporting(1);
    const t = setInterval(() => setImporting((v) => (v >= 100 ? (clearInterval(t), 100) : v + 12)), 180);
  };

  return (
    <>
      <PageHeader eyebrow="Example Plugin" title="Tools" lede="Licence, import and export, and the things you only do once." />
      <div className="bw-page__body" style={{ paddingBottom: 24 }}>
        <div className="bw-panels" style={{ maxWidth: 780 }}>
          <Card title="Licence" eyebrow="Updates" actions={<Badge tone="success">Active</Badge>}
            note="Updates install from GitHub Releases while the licence is active."
            footer={<><Button variant="link">Deactivate</Button><Button variant="primary">Check for updates</Button></>}>
            <div className="bw-fields">
              <Field label="Licence key" htmlFor="lk"><Input id="lk" mono defaultValue="BWX-4F46-E5A2-1180" /></Field>
              <Field label="Renews" htmlFor="rn"><Input id="rn" readOnly defaultValue="14 February 2027" /></Field>
            </div>
          </Card>

          <Card title="Import" eyebrow="Data" note="A CSV of members: name, email, plan, joined date.">
            <div style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
              <Button icon="upload" onClick={runImport}>Choose CSV</Button>
              <span style={{ fontSize: 12, color: 'var(--bw-text-muted)' }}>members-2026.csv · 318 rows</span>
            </div>
            {importing ? (
              <div style={{ marginTop: 16 }}>
                <ProgressBar value={importing} label={importing >= 100 ? 'Import complete' : 'Importing members'} showPct />
              </div>
            ) : null}
          </Card>

          <Card title="Export" eyebrow="Data" note="Everything this plugin stores about this site, as JSON.">
            <div style={{ display: 'flex', gap: 10 }}>
              <Button icon="download">Export settings</Button>
              <Button icon="download">Export members</Button>
            </div>
          </Card>

          <Notice tone="danger" title="Danger zone" actions={<Button size="sm" variant="danger" icon="trash-2" onClick={() => setConfirm(true)}>Delete all plugin data</Button>}>
            Removes every plan, member record and setting this plugin created. Your WordPress users are left alone.
          </Notice>
        </div>
      </div>

      <Modal open={confirm} title="Delete all plugin data?" subtitle="This cannot be undone."
        onClose={() => setConfirm(false)}
        footer={<><Button onClick={() => setConfirm(false)}>Cancel</Button><Button variant="danger" onClick={() => setConfirm(false)}>Delete everything</Button></>}>
        <p style={{ margin: '0 0 12px' }}>318 members, 4 plans and 27 scheduled renewals will be removed. WordPress users and posts are untouched.</p>
        <Field label="Type DELETE to confirm" htmlFor="cf"><Input id="cf" mono placeholder="DELETE" /></Field>
      </Modal>
    </>
  );
}
