A value the user picked or a piece of read-only reference data (roles with access, applied filters).

```jsx
<div className="bw-chips">
  <Chip>Administrator</Chip>
  <Chip onRemove={() => remove('editor')}>Editor</Chip>
</div>
```

Wrap groups in `.bw-chips` for consistent wrapping and gaps. Sentence case, not uppercase — that is what Badge is for.
