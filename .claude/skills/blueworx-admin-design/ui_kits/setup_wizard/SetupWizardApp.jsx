import React from 'react';
import { Tabs } from '../../components/layout/Tabs.jsx';
import { SaveBar } from '../../components/layout/SaveBar.jsx';
import { Button } from '../../components/core/Button.jsx';
import { ProgressBar } from '../../components/feedback/ProgressBar.jsx';
import { Notice } from '../../components/feedback/Notice.jsx';
import { StepBasics } from './StepBasics.jsx';
import { StepLook } from './StepLook.jsx';
import { StepAccess } from './StepAccess.jsx';
import { StepFinish } from './StepFinish.jsx';

const STEPS = [
  { id: 'basics', label: 'Basics' },
  { id: 'look', label: 'Look' },
  { id: 'access', label: 'Access' },
  { id: 'finish', label: 'Finish' },
];

/** First-run setup: full-bleed inside the WordPress content area, one step per tab. */
export function SetupWizardApp() {
  const [step, setStep] = React.useState('basics');
  const [dirty, setDirty] = React.useState(false);
  const [saving, setSaving] = React.useState(false);
  const [published, setPublished] = React.useState(false);
  const index = STEPS.findIndex((s) => s.id === step);
  const pct = Math.round(((index + (published ? 1 : 0)) / STEPS.length) * 100);
  const touch = () => setDirty(true);

  const save = () => { setSaving(true); setTimeout(() => { setSaving(false); setDirty(false); }, 700); };

  return (
    <div className="bw-admin bw-page">
      <header className="bw-pagehead">
        <div className="bw-pagehead__titles">
          <p className="bw-pagehead__eyebrow">Example Plugin</p>
          <h1 className="bw-pagehead__h1">Set up the members area</h1>
          <p className="bw-pagehead__lede">Four steps. You can change any of it later in Settings.</p>
        </div>
        <div style={{ minWidth: 220 }}>
          <ProgressBar value={pct} label="Setup complete" showPct />
        </div>
      </header>

      <Tabs value={step} onChange={setStep} items={STEPS} />

      {published ? (
        <div className="wp-notice-slot">
          <Notice tone="success" title="Members area published" onDismiss={() => setPublished(false)}>
            /members is live. Members can sign in as soon as you connect a payment provider.
          </Notice>
        </div>
      ) : null}

      <div className="bw-page__body" style={{ paddingBottom: 24 }}>
        <div className="bw-panels" style={{ maxWidth: 860 }}>
          {step === 'basics' ? <StepBasics onTouch={touch} /> : null}
          {step === 'look' ? <StepLook onTouch={touch} /> : null}
          {step === 'access' ? <StepAccess onTouch={touch} /> : null}
          {step === 'finish' ? <StepFinish onDone={() => { setPublished(true); setDirty(false); }} /> : null}
        </div>
      </div>

      <SaveBar dirty={dirty} saving={saving} onSave={save} onDiscard={() => setDirty(false)}>
        <Button disabled={index === 0} onClick={() => setStep(STEPS[Math.max(0, index - 1)].id)}>Back</Button>
        <Button disabled={index === STEPS.length - 1} iconRight="arrow-right" onClick={() => setStep(STEPS[Math.min(STEPS.length - 1, index + 1)].id)}>Next step</Button>
      </SaveBar>
    </div>
  );
}
