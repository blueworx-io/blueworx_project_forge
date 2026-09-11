import { useEffect, useState } from 'react';
import { CalendarCheck, Columns3, Inbox, LayoutDashboard, Receipt, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button, Card, EmptyState, SectionTitle, Tag, ToastProvider } from '../kit';
import { clientData } from './data';
import { Dashboard } from './screens/Dashboard';
import { Board } from './screens/Board';

/*
 * The client workspace shell (#297): banner, header with the nav, the screen,
 * and the footer. The five screens arrive one issue at a time (#299–#303);
 * until then each says what it will be.
 *
 * Navigation is the URL hash, so a screen can be linked to and the back button
 * works, without asking WordPress for a route it does not have.
 */

type ScreenName = 'dashboard' | 'board' | 'requests' | 'sales' | 'onboarding';

interface Screen {
  id: ScreenName;
  label: string;
  title: string;
  sub: string;
  icon: LucideIcon;
}

const SCREENS: Screen[] = [
  {
    id: 'dashboard',
    label: 'Dashboard',
    title: 'Dashboard',
    sub: 'Everything on your account, in one place. You can add information and raise requests here — moving work along is our job.',
    icon: LayoutDashboard,
  },
  {
    id: 'board',
    label: 'Your work',
    title: 'Your work',
    sub: 'Your work, in the stage it is really in. You can read everything and open any card — moving work between stages is our job.',
    icon: Columns3,
  },
  {
    id: 'requests',
    label: 'Bugs & requests',
    title: 'Bugs & requests',
    sub: 'Report a bug, or raise a request, idea or suggestion. We read everything, and turn what we accept into scheduled work.',
    icon: Inbox,
  },
  {
    id: 'sales',
    label: 'Support & hours',
    title: 'Support & hours',
    sub: 'See what your plan covers and upgrade whenever you like. Your point of contact can also set it up for you.',
    icon: Receipt,
  },
  {
    id: 'onboarding',
    label: 'Launch readiness',
    title: 'Launch readiness',
    sub: 'The list we work through together before you go live. You can fill things in, attach what is asked for and send steps back to us — we set the list and we approve it.',
    icon: CalendarCheck,
  },
];

interface Route {
  screen: ScreenName;
  /** What follows the screen in the hash — the item or step on show. */
  sub: string;
}

function routeFromHash(): Route {
  const [ hash, ...rest ] = window.location.hash.replace( /^#/, '' ).split( '/' );
  const screen = SCREENS.some( ( s ) => s.id === hash ) ? ( hash as ScreenName ) : 'dashboard';
  return { screen, sub: rest.join( '/' ) };
}

export function App() {
  const data = clientData();
  const [ route, setRoute ] = useState< Route >( routeFromHash );
  const [ banner, setBanner ] = useState( true );
  const screen = route.screen;

  useEffect( () => {
    const onHash = () => setRoute( routeFromHash() );
    window.addEventListener( 'hashchange', onHash );
    return () => window.removeEventListener( 'hashchange', onHash );
  }, [] );

  const current = SCREENS.find( ( s ) => s.id === screen ) ?? SCREENS[ 0 ];
  const clientName = data?.client.connected ? data.client.name : 'Not connected to a studio yet';
  const userName = data?.user.name ?? '';

  // Nothing needs the client's attention until the dashboard reads it (#299);
  // the strip is here so the shell is complete, and it only shows with a count.
  const attention = 0;

  return (
    <ToastProvider>
      <div className="fc-shell" data-testid="bwx-client-app">
        { banner && attention > 0 && (
          <div className="fc-banner" role="region" aria-label="Needs your attention">
            { attention } items need your review or confirmation. <a href="#dashboard">See what needs you</a>
            <button type="button" className="fk-icon-btn" aria-label="Dismiss" onClick={ () => setBanner( false ) }>
              <X size={ 16 } strokeWidth={ 1.5 } aria-hidden="true" />
            </button>
          </div>
        ) }

        <header className="fc-header">
          <span className="fc-brand">
            <span className="fc-mark" aria-hidden="true">
              F
            </span>
            Forge
          </span>
          <span className="fc-client" data-testid="bwx-client-name">
            { clientName }
          </span>
          <nav className="fc-nav" aria-label="Workspace">
            { SCREENS.map( ( s ) => (
              <a key={ s.id } href={ `#${ s.id }` } className="fc-nav-item" aria-current={ s.id === screen ? 'page' : undefined }>
                { s.label }
              </a>
            ) ) }
          </nav>
          <span className="fc-account">
            { data?.client.connected && (
              <Tag tone="ok" dot>
                Connected
              </Tag>
            ) }
            { userName && <span className="fc-user">{ userName }</span> }
            { data && (
              <a className="fk-btn fc-signout" data-variant="secondary" data-size="sm" href={ data.logoutUrl }>
                Sign out
              </a>
            ) }
          </span>
        </header>

        <main className="fc-main">
          <SectionTitle sub={ current.sub }>{ current.title }</SectionTitle>
          { 'dashboard' === screen && data ? (
            <Dashboard />
          ) : 'board' === screen && data ? (
            <Board item={ route.sub } />
          ) : (
          <Card>
            <EmptyState
              icon={ current.icon }
              title={ `${ current.title } is on its way` }
              body="This screen is being built. Until it lands, the same information is on your WordPress dashboard."
              action={
                data ? (
                  <a className="fk-btn" data-variant="secondary" data-size="md" href={ data.adminUrl }>
                    Open the dashboard screens
                  </a>
                ) : (
                  <Button variant="secondary" disabled>
                    Running outside WordPress
                  </Button>
                )
              }
            />
          </Card>
          ) }
        </main>

        <footer className="fc-footer">
          <span>Forge client workspace · embedded in your WordPress site</span>
          { data && <span>Forge client { data.version }</span> }
        </footer>
      </div>
    </ToastProvider>
  );
}
