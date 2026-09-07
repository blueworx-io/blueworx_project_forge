Main column plus sticky sidebar — status, related links, help.

```jsx
<div className="bw-page__body">
  <ScreenLayout sidebar={<><Card title="Status">…</Card><Card title="Need a hand?">…</Card></>}>
    <Card title="Details">…</Card>
  </ScreenLayout>
</div>
```

Collapses to one column under 1100px.
