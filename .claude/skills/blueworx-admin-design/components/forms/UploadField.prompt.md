Data files: CSV imports, JSON restores. Images use MediaField.

```jsx
<UploadField hint="CSV up to 5 MB. Columns: name, email, plan, joined." onChoose={pick} />
<UploadField file="members-2026.csv" hint="318 rows" onRemove={clear} />
```
