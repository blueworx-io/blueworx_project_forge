The panel a plugin settings screen is actually made of: one subject, a description, its rows, its own Save.

```jsx
<SettingsCard
  eyebrow="Payments"
  title="Checkout"
  description="Applies to every plan in this store."
  aside={<Badge tone="warning">Test mode</Badge>}
  dirty={dirty}
  onSave={save}
  onReset={reset}>
  <FormRow label="Currency" htmlFor="cur"><Select id="cur" options={['GBP £', 'EUR €']} /></FormRow>
  <FormRow label="Test mode" tip="Nothing is charged while test mode is on."><Switch bare aria-label="Test mode" /></FormRow>
</SettingsCard>
```

Save is disabled until `dirty`. Pass `hideFooter` when the screen has one `SaveBar` covering
every panel — never show both a per-card Save and a SaveBar for the same fields.
FormRow children give the label-left shape; `.bw-fields` + `Field` gives the two-column shape.
