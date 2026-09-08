Phases against a week or date scale. Use it for a schedule somebody sets by hand — a project
timeline, a rollout, a content plan.

```jsx
<Gantt
  span={26}
  ticks={['Week 1', 'Week 5', 'Week 9', 'Week 13', 'Week 17', 'Week 21', 'Week 25']}
  phases={[
    { id: 1, title: 'UI design', range: 'Weeks 4–7 · Design sign-off', start: 4, end: 7, kind: 'pre', label: 'Design sign-off' },
    { id: 2, title: 'Launch', range: 'Week 15 · Launch', start: 15, end: 15, kind: 'launch', label: 'Launch' },
    { id: 3, title: 'Optimisation', range: 'Weeks 18–26', start: 18, end: 26, kind: 'post' },
  ]}
  actions={(p) => <IconButton icon="pencil" label={`Edit ${p.title}`} size="sm" />} />
```

**Weeks are authored, never derived from hours.** A schedule reflects the team's real
availability; an estimate reflects the work. Deriving one from the other produces a plan
nobody can keep.

`kind` sets the bar's colour and its meaning: `pre` before launch, `launch` for the milestone
that divides the two halves, `post` after. `visible: false` dims a bar — planned internally,
not shown to the client.

The bar's position is the only thing set inline, because it is data. Never style a bar by
hand; if you need a fourth kind, add it to the design system.
