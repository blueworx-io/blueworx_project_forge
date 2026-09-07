The panel. Group settings by subject, one Card each, stacked with `gap: var(--bw-stack-gap)` in `.bw-panels`.

```jsx
<Card title="Checkout" eyebrow="Payments" note="Applies to every product in this store."
  footer={<Button variant="primary">Save</Button>}>
  <div className="bw-fields">…</div>
</Card>
<Card flush title="Orders"><DataTable … /></Card>
```
