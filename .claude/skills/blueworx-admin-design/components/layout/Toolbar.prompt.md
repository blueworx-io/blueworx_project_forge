Search and filters above a table. First child of a flush Card, before the DataTable.

```jsx
<Card flush title="Members">
  <Toolbar right={<Pagination page={1} totalPages={16} compact />}>
    <span className="bw-toolbar__search"><Input icon="search" size="sm" placeholder="Search members" /></span>
    <span style={{ width: 150 }}><Select placeholder="All plans" options={plans} /></span>
    <BulkActions count={sel.length} actions={['Export', 'Delete']} />
  </Toolbar>
  <DataTable … />
</Card>
```
