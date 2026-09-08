The band of derived figures that stays put while an editor's tabs change beneath it. Flush
with the page header, hairline underneath, one cell per figure divided by vertical rules.

Figures only. Nothing in this strip is editable, and nothing in it is a link — use
`StatCard` in `.bw-stats` for figures that sit on the canvas as cards.

```jsx
<SummaryStrip cells={[
  { label: 'Project estimate', value: '232 hrs', foot: '18 line items' },
  { label: 'In package calculation', value: '296 hrs', foot: 'Across both builders' },
  { label: 'Readiness', value: '71%', foot: '5 of 7 done' },
]} />
```
