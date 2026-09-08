Confirmations and short forms. Anything longer belongs on its own screen.

```jsx
<Modal open={open} title="Delete plan?" subtitle="This cannot be undone." onClose={close}
  footer={<><Button onClick={close}>Cancel</Button><Button variant="danger">Delete plan</Button></>}>
  <p>Members on this plan keep access until the end of their billing period.</p>
</Modal>
```
