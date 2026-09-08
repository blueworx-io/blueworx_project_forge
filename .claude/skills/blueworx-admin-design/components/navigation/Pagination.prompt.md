Paging for a DataTable. Lives in the `.bw-tablefoot` strip at the bottom of a flush Card.

```jsx
<div className="bw-tablefoot">
  <span>Showing 1–20</span>
  <Pagination page={page} totalPages={16} totalItems={1204} onChange={setPage} />
</div>
```

Use `compact` when the toolbar is tight.
