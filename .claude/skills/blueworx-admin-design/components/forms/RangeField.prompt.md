A bounded number the user tunes by feel.

```jsx
<FormRow label="Cache lifetime"><RangeField value={mins} min={0} max={120} step={5} unit=" min" onChange={setMins} /></FormRow>
```

For an exact value, use `<Input type="number">` instead.
