The pipe-separated links WordPress users expect under a list-table row title.

```jsx
<td>
  <span className="bw-table__primary">Pro annual</span>
  <RowActions actions={[{ label: 'Edit', onClick: edit }, { label: 'Duplicate' }, { label: 'Trash', danger: true }]} />
</td>
```

Use this for text-link row actions; use IconButton row actions when the table is dense.
