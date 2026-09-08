Use for every action on a BlueWorx admin screen; exactly one `primary` per region (usually "Save changes" in the SaveBar).

```jsx
<Button variant="primary" icon="circle-check">Save changes</Button>
<Button>Cancel</Button>
<Button variant="ghost" size="sm" icon="refresh-cw">Refresh</Button>
<Button variant="danger" icon="trash-2">Delete plan</Button>
```

Variants: `primary` (brand indigo), `secondary` (white/outline, the default), `ghost` (toolbar), `danger`, `link`. Sizes `sm | md | lg`; `block` stretches. Pass `href` to render an anchor. Never stack two primaries side by side.
