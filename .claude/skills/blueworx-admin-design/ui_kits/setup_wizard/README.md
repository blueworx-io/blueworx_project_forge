# UI kit — first-run setup wizard

The screen a BlueWorx plugin shows the first time it is activated. Modelled on the real
Setup screen in `BlueWorx internal plugin admin CSS` (`assets/css/admin-setup.css`):
full-bleed inside `#wpcontent`, header with a progress read-out, one tab per step, and a
sticky save bar that carries Back / Next as well as Save.

**Open `index.html`.**

| File | What it is |
|---|---|
| `SetupWizardApp.jsx` | Shell: header, progress, step tabs, save bar, published state. |
| `StepBasics.jsx` | Names, slug, timezone, support email. |
| `StepLook.jsx` | Look cards (radio cards with a miniature preview), logo, accent colour. |
| `StepAccess.jsx` | Role tick-list with a locked Administrator row. |
| `StepFinish.jsx` | Read-only summary table and the publish action. |

## Rules this kit encodes

- The wizard is a **screen**, not a modal — WordPress screens do not trap the user.
- Progress is stated as a percentage in Sora, with the 8px pill track underneath.
- Save is always reachable: the bar is `position:sticky`, never `fixed`, so the last field
  is never trapped underneath it.
- Nothing is destructive and nothing is live until the explicit publish action on step 4.
