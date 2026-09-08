Bar pinned to the bottom of the window on any screen with a form. It is in the same place whether the screen is one card or fifty. Save is disabled until something changes.

```jsx
<SaveBar dirty={dirty} saving={saving} onSave={save} onDiscard={reset} />
```

Put it as the last child of `.bw-page`. It is fixed, so it takes no room in the flow — `.bw-page` keeps that room itself, and inside wp-admin the bar clears the admin menu on its own. One per screen.
