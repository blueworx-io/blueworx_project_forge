Records list. Put it in a `flush` Card so the table meets the card edges.

```jsx
<Card flush title="Members" actions={<Button size="sm" icon="download">Export</Button>}>
  <DataTable
    columns={[{ key: 'name', label: 'Member' }, { key: 'plan', label: 'Plan' }, { key: 'spend', label: 'Spend', align: 'right' }]}
    rows={rows}
    selectable selected={sel} onToggle={toggle} onToggleAll={toggleAll}
    rowActions={(r) => <IconButton icon="edit" label="Edit" size="sm" />}
    empty={<EmptyState icon="groups" title="No members yet" />} />
</Card>
```

Use `<span className="bw-table__primary">` + `<span className="bw-table__sub">` inside the first cell for a name with a secondary line.

## Rows that fall into groups

Pass `groups` instead of `rows` when the rows belong to named categories — an estimate's
phases, a schedule's stages. Each group draws a header row on the sunken surface, and
`total` closes the table off.

```jsx
<DataTable
  columns={[{ key: 'title', label: 'Work item' }, { key: 'hours', label: 'Hours', align: 'right' }]}
  groups={[
    { id: 'discovery', title: 'Discovery', subtotalLabel: 'Phase subtotal 24 hrs', rows: discoveryRows },
    { id: 'design', title: 'UI design', subtotalLabel: 'Phase subtotal 48 hrs', rows: designRows },
  ]}
  total={{ label: 'Project total', value: '232 hrs' }} />
```

**The table sums nothing.** `subtotalLabel` and `total.value` are already-formatted figures
the caller works out, because only the caller knows which rows count towards which figure —
an item can be quoted to a client and still be excluded from a total.

Add an internal note to a row with `<span className="bw-table__note">`: a warning-coloured
line for the administrator that must never reach a client-facing render.
