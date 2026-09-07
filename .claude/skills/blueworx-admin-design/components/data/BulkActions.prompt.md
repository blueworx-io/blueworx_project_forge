Acts on a DataTable's checked rows. Put it in the Toolbar.

```jsx
<BulkActions count={sel.length} actions={['Export', 'Change plan', 'Remove']} value={action} onChange={setAction} onApply={run} />
```

Disabled until something is selected and an action is chosen. Destructive bulk actions confirm in a Modal.
