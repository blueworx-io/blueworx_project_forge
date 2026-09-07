Icon-only action where a label would crowd the layout — row actions, card header overflow, close buttons.

```jsx
<IconButton icon="pencil" label="Edit" />
<IconButton icon="trash-2" label="Delete" variant="danger" />
<IconButton icon="ellipsis" label="More actions" size="sm" />
```

`label` is mandatory — it is the tooltip and the accessible name. Use `outline` when it sits alone outside a group.
