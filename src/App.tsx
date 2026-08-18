export interface ForgeData {
  restUrl: string;
  nonce: string;
  isLoggedIn: boolean;
  canEdit: boolean;
  canManage: boolean;
  siteUrl: string;
  loginUrl: string;
  logoutUrl: string;
  version: string;
}

declare global {
  interface Window {
    bwxForgeData?: ForgeData;
  }
}

/**
 * The skeleton screen. It exists to prove the pipeline end to end — the bundle
 * builds, WordPress serves it, it mounts, and it can read what the server told
 * it about the current user. The real screens replace it, built from the design.
 */
export function App() {
  const data = window.bwxForgeData;

  return (
    <main
      data-testid="bwx-forge-ready"
      style={ {
        display: 'flex',
        minHeight: '100dvh',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 8,
        fontFamily: 'system-ui, sans-serif',
        color: '#1a1f36',
        backgroundColor: '#fafbfc',
      } }
    >
      <h1 style={ { fontSize: 20, fontWeight: 600, margin: 0 } }>Blueworx Forge</h1>
      <p style={ { margin: 0, color: '#64748b', fontSize: 13 } }>
        { data ? `Version ${ data.version } — ready` : 'Running without WordPress data' }
      </p>
    </main>
  );
}
