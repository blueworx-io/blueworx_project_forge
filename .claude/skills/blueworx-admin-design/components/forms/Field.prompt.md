Wraps every control with its label, help text and error. Group fields in a `.bw-fields` grid (two columns, one on mobile).

```jsx
<div className="bw-fields">
  <Field label="Plugin name" htmlFor="name" help="Shown in the admin menu.">
    <Input id="name" defaultValue="Blueworx Labs Example Plugin" />
  </Field>
  <Field label="License key" htmlFor="key" error="That key is not recognised.">
    <Input id="key" mono invalid defaultValue="BWX-0000" />
  </Field>
</div>
```

Add `wide` to span both columns. Help is one sentence; errors say what to do next.
