On/off for a capability or visibility setting — the state, not a choice. The toggle always sits to the right of the label it switches.

```jsx
<Switch label="Show the reports page" defaultChecked />
<Switch bare aria-label="Enable module" />
<Switch label="Account details" help="Needs Advanced Custom Fields." disabled />
```

`bare` drops the pill wrapper for settings rows and table cells, and takes the full width so the toggle lands on the row's right edge.

`disabled` is a real state, not just a dead control: the row fades and the cursor says no, while the track keeps its on or off colour so a locked setting still reads as on or off.
