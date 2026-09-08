Confirmation of something that already worked, with an escape hatch. Never for errors — those are Notices.

```jsx
<ToastStack>
  <Toast icon="circle-check" action={undo} actionLabel="Undo" onDismiss={hide}>Member removed.</Toast>
</ToastStack>
```

One ToastStack per screen; dismiss after about five seconds.
