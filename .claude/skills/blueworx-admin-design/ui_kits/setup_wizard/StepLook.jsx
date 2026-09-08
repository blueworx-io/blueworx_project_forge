import React from 'react';
import { Card } from '../../components/layout/Card.jsx';
import { Field } from '../../components/forms/Field.jsx';
import { Input } from '../../components/forms/Input.jsx';
import { MediaField } from '../../components/forms/MediaField.jsx';

const LOOKS = [
  { id: 'bright', name: 'Bright', desc: 'High contrast, condensed titles.', accent: '#4F46E5', ink: '#0A0C29' },
  { id: 'floodlight', name: 'Floodlight', desc: 'Dark surfaces, bright accent.', accent: '#00A32A', ink: '#14161A' },
  { id: 'editorial', name: 'Editorial', desc: 'Quiet, editorial, serif titles.', accent: '#B32D2E', ink: '#2B1B18' },
];

export function StepLook({ onTouch }) {
  const [look, setLook] = React.useState('bright');
  return (
    <Card title="Pick a look" eyebrow="Step 2" note="Applies to the members area only. You can change it whenever you like.">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 14 }}>
        {LOOKS.map((l) => (
          <label key={l.id}
            style={{
              display: 'block', padding: 12, borderRadius: 'var(--bw-radius-md)', cursor: 'pointer', position: 'relative',
              border: `2px solid ${look === l.id ? 'var(--bw-brand)' : 'var(--bw-border)'}`,
              background: look === l.id ? 'var(--bw-brand-wash)' : 'var(--bw-surface-card)',
            }}>
            <input type="radio" name="look" checked={look === l.id} onChange={() => { setLook(l.id); onTouch && onTouch(); }}
              style={{ position: 'absolute', top: 12, right: 12, accentColor: 'var(--bw-brand)' }} />
            <span style={{ display: 'block', height: 84, borderRadius: 'var(--bw-radius-sm)', padding: 12, marginBottom: 12, background: '#fff', border: '1px solid var(--bw-border)' }}>
              <span style={{ display: 'block', height: 8, width: '40%', borderRadius: 999, background: l.ink, marginBottom: 8 }} />
              <span style={{ display: 'block', height: 22, borderRadius: 6, background: l.accent, marginBottom: 8 }} />
              <span style={{ display: 'inline-block', height: 6, width: '28%', borderRadius: 999, background: 'var(--bw-line)', marginRight: 6 }} />
              <span style={{ display: 'inline-block', height: 6, width: '18%', borderRadius: 999, background: 'var(--bw-line)' }} />
            </span>
            <span style={{ display: 'block', fontFamily: 'var(--bw-font-display)', fontWeight: 600, fontSize: 14, margin: '4px 0' }}>{l.name}</span>
            <span style={{ display: 'block', fontSize: 12, color: 'var(--bw-text-muted)' }}>{l.desc}</span>
          </label>
        ))}
      </div>
      <div className="bw-fields" style={{ marginTop: 20 }}>
        <Field label="Logo" help="PNG or SVG, at least 320px wide."><MediaField onChoose={onTouch} /></Field>
        <Field label="Accent colour" htmlFor="w-ac">
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <span style={{ width: 34, height: 34, borderRadius: 8, background: 'var(--bw-brand)', border: '1px solid var(--bw-border)', flex: 'none' }} />
            <Input id="w-ac" mono defaultValue="#4F46E5" onChange={onTouch} />
          </div>
        </Field>
      </div>
    </Card>
  );
}
