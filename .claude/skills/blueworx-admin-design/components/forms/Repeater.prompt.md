Lists the site owner builds themselves.

```jsx
<Repeater
  items={plans}
  addLabel="Add plan"
  onAdd={add}
  onRemove={remove}
  renderRow={(p) => (<>
    <Input defaultValue={p.name} aria-label="Plan name" />
    <Input defaultValue={p.price} affix="/yr" aria-label="Price" />
  </>)}
  emptyLabel="No plans yet. Add one to start taking memberships." />
```
