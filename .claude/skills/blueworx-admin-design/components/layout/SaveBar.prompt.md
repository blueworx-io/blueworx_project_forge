Sticky bar at the bottom of any screen with a form. Save is disabled until something changes.

```jsx
<SaveBar dirty={dirty} saving={saving} onSave={save} onDiscard={reset} />
```

Put it as the last child of `.bw-page` so `margin-top:auto` pins it and it still occupies flow space at the end of the form.
