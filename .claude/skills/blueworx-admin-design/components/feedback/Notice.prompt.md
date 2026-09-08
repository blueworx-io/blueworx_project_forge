Screen-level messages: results, warnings, connection state. Sits directly under the PageHeader or at the top of a Card body.

```jsx
<Notice tone="warning" title="Test mode is on">No live payments are being taken.</Notice>
<Notice tone="success" onDismiss={hide}>Settings saved.</Notice>
<Notice tone="danger" title="Licence expired" actions={<Button size="sm" variant="link">Renew</Button>} />
```
