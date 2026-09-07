Any colour setting. Presets default to the brand and status colours — never offer a bare picker alone.

```jsx
<FormRow label="Accent colour"><ColorField value={color} onChange={setColor} /></FormRow>
<ColorField value={c} presets={[]} onChange={setC} />
```
