Trail back to the parent list when a plugin has detail screens. Put it in PageHeader's children slot.

```jsx
<Breadcrumbs items={[{ id: 'members', label: 'Members' }, { label: 'Priya Raman' }]} onNavigate={go} />
```

The last item is always the current page and is never a link.
