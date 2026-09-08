Overflow actions — the row "…" menu, or a split action on a page header.

```jsx
<DropdownMenu trigger={<IconButton icon="ellipsis" label="More actions" size="sm" />}
  items={[
    { id: 'dup', label: 'Duplicate', icon: 'file' },
    { id: 'export', label: 'Export', icon: 'download' },
    { separator: true },
    { id: 'del', label: 'Delete', icon: 'trash-2', danger: true },
  ]}
  onSelect={handle} />
```

Closes on outside click and Escape. Keep it to seven items; beyond that, use a screen.
