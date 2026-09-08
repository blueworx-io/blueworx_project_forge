Inspect or edit a record without leaving the list.

```jsx
<Drawer open={!!row} title={row?.name} subtitle={row?.email} onClose={close}
  footer={<><Button onClick={close}>Close</Button><Button variant="primary">Save</Button></>}>
  <DescriptionList stack items={[['Plan', row.plan], ['Renews', '01 Sep 2026']]} />
</Drawer>
```

Drawer for detail; Modal for a decision.
