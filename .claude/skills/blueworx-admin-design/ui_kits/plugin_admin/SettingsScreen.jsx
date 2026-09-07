import React from 'react';
import { PageHeader } from '../../components/layout/PageHeader.jsx';
import { Card } from '../../components/layout/Card.jsx';
import { SectionNav } from '../../components/layout/SectionNav.jsx';
import { SaveBar } from '../../components/layout/SaveBar.jsx';
import { Button } from '../../components/core/Button.jsx';
import { Chip } from '../../components/core/Chip.jsx';
import { Field } from '../../components/forms/Field.jsx';
import { Input } from '../../components/forms/Input.jsx';
import { Textarea } from '../../components/forms/Textarea.jsx';
import { Select } from '../../components/forms/Select.jsx';
import { Switch } from '../../components/forms/Switch.jsx';
import { Checkbox } from '../../components/forms/Checkbox.jsx';
import { Radio, RadioGroup } from '../../components/forms/Radio.jsx';
import { MediaField } from '../../components/forms/MediaField.jsx';
import { Notice } from '../../components/feedback/Notice.jsx';

const SECTIONS = [
  { id: 'general', label: 'General' },
  { id: 'branding', label: 'Branding' },
  { id: 'payments', label: 'Payments' },
  { id: 'access', label: 'Access', meta: '4 roles' },
  { id: 'emails', label: 'Emails', meta: 6 },
];

export function SettingsScreen() {
  const [section, setSection] = React.useState('general');
  const [dirty, setDirty] = React.useState(false);
  const [saving, setSaving] = React.useState(false);
  const touch = () => setDirty(true);

  const save = () => {
    setSaving(true);
    setTimeout(() => { setSaving(false); setDirty(false); }, 700);
  };

  return (
    <>
      <PageHeader eyebrow="Example Plugin" title="Settings" lede="How the members area behaves on this site."
        actions={<Button icon="external-link">View members page</Button>}>
        <div className="bw-chips" style={{ marginTop: 10 }}>
          <span style={{ fontSize: 11, letterSpacing: '.12em', textTransform: 'uppercase', color: 'var(--bw-text-muted)' }}>Can manage</span>
          <Chip>Administrator</Chip>
          <Chip>Site manager</Chip>
        </div>
      </PageHeader>
      <div className="bw-page__body">
        <SectionNav items={SECTIONS} value={section} onChange={setSection} />
        <div className="bw-panels" style={{ paddingBottom: 24 }}>
          {section === 'general' ? (
            <>
              <Card title="Basics" eyebrow="General" note="Names and links used across the members area.">
                <div className="bw-fields">
                  <Field label="Site name" htmlFor="cn" help="Shown in the header and in emails."><Input id="cn" defaultValue="Example Site" onChange={touch} /></Field>
                  <Field label="Members page" htmlFor="mp"><Input id="mp" defaultValue="/members" onChange={touch} /></Field>
                  <Field label="Timezone" htmlFor="tz"><Select id="tz" options={['Europe/London', 'Europe/Dublin', 'UTC']} onChange={touch} /></Field>
                  <Field label="Support email" htmlFor="se"><Input id="se" type="email" defaultValue="support@example.com" onChange={touch} /></Field>
                  <Field wide label="Welcome message" htmlFor="wm" help="Shown once, after a member first signs in.">
                    <Textarea id="wm" rows={3} defaultValue="Welcome. Your membership is active — the details are on your account page." onChange={touch} />
                  </Field>
                </div>
              </Card>
              <Card title="Shortcodes" eyebrow="Placement" note="Paste these into any page or block.">
                <div className="bw-fields">
                  <Field label="Members list" htmlFor="s1"><Input id="s1" mono readOnly defaultValue="[bw_members]" /></Field>
                  <Field label="Reports" htmlFor="s2"><Input id="s2" mono readOnly defaultValue={'[bw_members plan="pro"]'} /></Field>
                </div>
              </Card>
            </>
          ) : null}

          {section === 'branding' ? (
            <Card title="Branding" eyebrow="Appearance" note="Applies to the members area only — the WordPress admin is untouched.">
              <div className="bw-fields">
                <Field label="Logo" htmlFor="lg" help="PNG or SVG, at least 320px wide."><MediaField onChoose={touch} /></Field>
                <Field label="Accent colour" htmlFor="ac" help="Used for buttons and links.">
                  <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                    <span style={{ width: 34, height: 34, borderRadius: 8, background: 'var(--bw-brand)', border: '1px solid var(--bw-border)', flex: 'none' }} />
                    <Input id="ac" mono defaultValue="#4F46E5" onChange={touch} />
                  </div>
                </Field>
                <Field wide label="Layout"><RadioGroup row>
                  <Radio name="ly" label="Cards" help="Default." defaultChecked onChange={touch} />
                  <Radio name="ly" label="Table" help="Denser, for large clubs." onChange={touch} />
                </RadioGroup></Field>
              </div>
            </Card>
          ) : null}

          {section === 'payments' ? (
            <>
              <Notice tone="warning" title="Test mode is on">No live payments are being taken on this site.</Notice>
              <Card title="Provider" eyebrow="Payments" note="Charges run through your connected provider; no card details touch this site.">
                <div className="bw-fields">
                  <Field label="Provider" htmlFor="pv"><Select id="pv" options={['Stripe', 'PayPal', 'Bank transfer']} onChange={touch} /></Field>
                  <Field label="Currency" htmlFor="cu"><Select id="cu" options={['GBP £', 'EUR €', 'USD $']} onChange={touch} /></Field>
                  <Field label="Publishable key" htmlFor="pk"><Input id="pk" mono defaultValue="pk_test_51Nx…" onChange={touch} /></Field>
                  <Field label="Secret key" htmlFor="sk" help="Stored encrypted in the options table."><Input id="sk" mono type="password" defaultValue="sk_test_51Nx" onChange={touch} /></Field>
                </div>
                <div style={{ marginTop: 18, display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                  <Switch label="Test mode" help="Nothing is charged." defaultChecked onChange={touch} />
                  <Switch label="Email receipts" defaultChecked onChange={touch} />
                  <Switch label="Retry failed payments" onChange={touch} />
                </div>
              </Card>
            </>
          ) : null}

          {section === 'access' ? (
            <Card title="Who can see what" eyebrow="Access" note="Roles inherit everything below them.">
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }}>
                <Checkbox label="Administrator" help="Full access" defaultChecked disabled />
                <Checkbox label="Site manager" help="Members, plans, emails" defaultChecked onChange={touch} />
                <Checkbox label="Author" help="Content only" defaultChecked onChange={touch} />
                <Checkbox label="Subscriber" help="Their own account" defaultChecked onChange={touch} />
                <Checkbox label="Contributor" onChange={touch} />
                <Checkbox label="Editor" onChange={touch} />
              </div>
            </Card>
          ) : null}

          {section === 'emails' ? (
            <Card flush title="Emails" eyebrow="Notifications">
              <table className="bw-table">
                <thead><tr><th>Email</th><th>Sent when</th><th style={{ width: 90 }}>Enabled</th></tr></thead>
                <tbody>
                  {[['Welcome', 'A member joins'], ['Receipt', 'A payment succeeds'], ['Payment failed', 'A charge is declined'], ['Renewal reminder', '7 days before renewal'], ['Cancelled', 'A member cancels'], ['Plan changed', "A member's plan changes"]].map(([n, w], i) => (
                    <tr key={n}>
                      <td><span className="bw-table__primary">{n}</span></td>
                      <td style={{ color: 'var(--bw-text-muted)' }}>{w}</td>
                      <td><Switch bare aria-label={n} defaultChecked={i !== 5} onChange={touch} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          ) : null}
        </div>
      </div>
      <SaveBar dirty={dirty} saving={saving} onSave={save} onDiscard={() => setDirty(false)} />
    </>
  );
}
