import React from 'react';
import { Card } from '../../components/layout/Card.jsx';
import { Field } from '../../components/forms/Field.jsx';
import { Input } from '../../components/forms/Input.jsx';
import { Select } from '../../components/forms/Select.jsx';
import { Textarea } from '../../components/forms/Textarea.jsx';

export function StepBasics({ onTouch }) {
  return (
    <Card title="Tell us about the site" eyebrow="Step 1" note="Used in the members area, in emails and on the account page.">
      <div className="bw-fields">
        <Field label="Site name" htmlFor="w-name" required><Input id="w-name" defaultValue="Example Site" onChange={onTouch} /></Field>
        <Field label="Members page slug" htmlFor="w-slug" help="Created for you if it does not exist."><Input id="w-slug" mono defaultValue="/members" onChange={onTouch} /></Field>
        <Field label="Timezone" htmlFor="w-tz"><Select id="w-tz" options={['Europe/London', 'Europe/Dublin', 'UTC']} onChange={onTouch} /></Field>
        <Field label="Support email" htmlFor="w-em"><Input id="w-em" type="email" defaultValue="support@example.com" onChange={onTouch} /></Field>
        <Field wide label="One line about the site" htmlFor="w-bio" help="Shown at the top of the members area.">
          <Textarea id="w-bio" rows={2} defaultValue="A short line about this site, shown at the top of the members area." onChange={onTouch} />
        </Field>
      </div>
    </Card>
  );
}
