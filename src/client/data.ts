/**
 * What the client plugin hands the app when it serves the page (#297).
 * Absent when the app runs on its own under `npm run dev:client`.
 */
export interface ClientData {
  restUrl: string;
  nonce: string;
  siteUrl: string;
  adminUrl: string;
  logoutUrl: string;
  version: string;
  client: { name: string; connected: boolean };
  user: { name: string };
}

declare global {
  interface Window {
    bwxForgeClientData?: ClientData;
  }
}

export function clientData(): ClientData | null {
  return window.bwxForgeClientData ?? null;
}
