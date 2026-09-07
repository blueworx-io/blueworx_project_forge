Logo, favicon and image settings — the pattern the WordPress media modal fills in.

```jsx
<Field label="Logo" help="Displayed in the site header.">
  <MediaField src={logo} hint="PNG or SVG, at least 320px wide." onChoose={open} onRemove={clear} />
</Field>
```

Empty state is a dashed 56px square, never a large drop zone — admin screens have no room for one.
