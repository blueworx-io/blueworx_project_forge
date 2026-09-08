Left rail on settings screens with more than about four sections; pairs with `.bw-page__body`.

```jsx
<div className="bw-page__body">
  <SectionNav value={section} onChange={setSection}
    items={[{ id: 'general', label: 'General' }, { id: 'access', label: 'Access', meta: '4 roles' }]} />
  <div className="bw-panels">…</div>
</div>
```
