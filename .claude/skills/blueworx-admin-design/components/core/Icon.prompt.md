Renders one Lucide icon. Every BlueWorx admin screen takes its icons from Lucide — never hand-draw an SVG, never use emoji.

```jsx
<Icon name="settings" size={18} />
<Icon name="circle-check" size={16} color="var(--bw-success-deep)" label="Connected" />
```

`name` is the Lucide name in kebab-case (the `lucide-react` component `<CircleCheck />` is `"circle-check"` here). Sizes: 14 in small controls, 16 inline with 13px text, 18 in buttons and menu rows, 20–28 in empty states. `strokeWidth` defaults to 2, matching `lucide-react`.

Common admin picks: `settings`, `users`, `plug`, `shopping-cart`, `chart-column`, `refresh-cw`, `trash-2`, `pencil`, `ellipsis`, `arrow-right`, `chevron-down`, `circle-check`, `triangle-alert`, `info`, `search`, `funnel`, `mail`, `lock`, `eye`, `calendar`, `external-link`, `x`.

Icons live in `assets/icons/lucide-icons.js`. If a screen needs one that is not there yet, copy its geometry from `lucide-icons/lucide` into that file rather than approximating with a near-miss. The old Dashicons names still resolve through a compatibility alias map, but new code should use Lucide names.
