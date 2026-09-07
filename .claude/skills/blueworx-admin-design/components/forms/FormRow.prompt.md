Dense settings in the shape WordPress developers already know — label left, control right.

```jsx
<Card title="Checkout">
  <FormRow label="Currency" htmlFor="cur" help="Applies to new orders only.">
    <Select id="cur" options={['GBP £', 'EUR €']} />
  </FormRow>
  <FormRow label="Test mode" tip="Nothing is charged while test mode is on.">
    <Switch bare aria-label="Test mode" defaultChecked />
  </FormRow>
</Card>
```

FormRow for long settings lists; `Field` inside `.bw-fields` for two-column forms.
