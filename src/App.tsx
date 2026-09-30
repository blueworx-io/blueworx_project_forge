import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { BarChart3, Bell, Building2, CalendarCheck, CalendarClock, CalendarDays, CircleUser, Clock, Columns3, CreditCard, ExternalLink, FileCheck2, GanttChart, Gauge, Inbox, LifeBuoy, ListChecks, Receipt, RefreshCw, Repeat, ScrollText, Users, X } from 'lucide-react';
import type { ScreenName, ViewName } from './types';
import { api, forgeData, forgetAll, isConnected, onRefreshed, refreshedAt } from './api';
import { ClientChoiceProvider, ClientPicker, useClientChoice } from './ClientChoice';
import { AvailabilityScreen } from './components/AvailabilityScreen';
import { CapacityScreen } from './components/CapacityScreen';
import { ClientsScreen } from './components/ClientsScreen';
import { MeetingsScreen } from './components/MeetingsScreen';
import { ProfileScreen } from './components/ProfileScreen';
import { MyTasksScreen } from './components/MyTasksScreen';
import { OnboardingScreen } from './components/OnboardingScreen';
import { PackagesScreen } from './components/PackagesScreen';
import { SettingsScreen } from './components/SettingsScreen';
import { PeopleScreen } from './components/PeopleScreen';
import { QueueScreen } from './components/QueueScreen';
import { RecurringScreen } from './components/RecurringScreen';
import { RemindersScreen } from './components/RemindersScreen';
import { ReportsScreen } from './components/ReportsScreen';
import { Signals } from './components/Signals';
import { StandupScreen } from './components/StandupScreen';
import { SubscriptionsScreen } from './components/SubscriptionsScreen';
import { SupportScreen } from './components/SupportScreen';
import { Screen } from './components/States';
import { RailCount } from './sidebarCounts';
import { TabBar } from './components/TabBar';
import { WorkScreen } from './components/WorkScreen';
import { Avatar, Button, PageHeader } from './kit';
import type { TileHue } from './kit';
import './shell.css';
import { onCompactChange, useCompact } from './viewport';

/**
 * The studio application shell (#304): a rail down the left with the screens
 * in groups, a bar across the top that says where you are, and the screen.
 *
 * The rail owns which screen is open and, for the work screen, which view —
 * Kanban, Gantt and Calendar are three entries that open one screen, so the
 * board's own view switch and the rail can never disagree. Which client is
 * being looked at is the top bar's one Client picker (#402), and every
 * client screen follows it.
 *
 * What has happened lately (#175) stays in the top bar rather than being a
 * screen, because a request arriving, or your work coming back, matters the
 * same amount whichever screen somebody is looking at.
 *
 * Every screen the studio uses is here (ARCH-7): the configuration screens —
 * Clients, Support, Meetings, People, Availability, Packages — came in one
 * pull request each (spec 2026-09-16), and their WordPress admin pages are
 * gone. Sync health is the one link out: it is a WordPress admin screen
 * because it is about WordPress itself, and the rail links to it so it is no
 * further away than it was.
 */

type Entry =
  | { group: string }
  | { key: ScreenName; label: string; icon: LucideIcon; testId: string; view?: ViewName; admin?: true; primary?: true }
  | { href: string; label: string; icon: LucideIcon; testId: string; admin?: true };

const RAIL: Entry[] = [
  { group: 'My day' },
  { key: 'mytasks', label: 'My tasks', icon: ListChecks, testId: 'bwx-screen-mytasks', primary: true },
  { key: 'standup', label: 'Daily standup', icon: Clock, testId: 'bwx-screen-standup', primary: true },
  { group: 'Intake' },
  { key: 'requests', label: 'Requests review', icon: Inbox, testId: 'bwx-screen-requests', primary: true },
  { group: 'Delivery' },
  { key: 'work', view: 'board', label: 'Kanban', icon: Columns3, testId: 'bwx-screen-work' },
  { key: 'work', view: 'gantt', label: 'Gantt', icon: GanttChart, testId: 'bwx-screen-gantt' },
  { key: 'work', view: 'calendar', label: 'Calendar', icon: CalendarDays, testId: 'bwx-screen-calendar', primary: true },
  { key: 'capacity', label: 'Capacity', icon: Gauge, testId: 'bwx-screen-capacity' },
  { key: 'recurring', label: 'Recurring tasks', icon: Repeat, testId: 'bwx-screen-recurring' },
  { key: 'reminders', label: 'Reminders', icon: Bell, testId: 'bwx-screen-reminders' },
  { group: 'Clients' },
  { key: 'clients', label: 'Clients', icon: Building2, testId: 'bwx-screen-clients' },
  { key: 'support', label: 'Support', icon: LifeBuoy, testId: 'bwx-screen-support' },
  { key: 'meetings', label: 'Meetings', icon: CalendarClock, testId: 'bwx-screen-meetings' },
  { key: 'onboarding', label: 'Onboarding board', icon: FileCheck2, testId: 'bwx-screen-onboarding' },
  { href: 'admin.php?page=blueworx-forge-sync', label: 'Sync health', icon: RefreshCw, testId: 'bwx-link-sync', admin: true },
  { group: 'Team' },
  { key: 'people', label: 'People', icon: Users, testId: 'bwx-screen-people', admin: true },
  { key: 'availability', label: 'Availability', icon: CalendarCheck, testId: 'bwx-screen-availability', admin: true },
  { group: 'Insight' },
  { key: 'reports', label: 'Reports', icon: BarChart3, testId: 'bwx-screen-reports', admin: true },
  { key: 'subscriptions', label: 'Subscriptions', icon: CreditCard, testId: 'bwx-screen-subscriptions', admin: true },
  { key: 'packages', label: 'Packages', icon: Receipt, testId: 'bwx-screen-packages', admin: true },
  { key: 'settings', label: 'Settings', icon: ScrollText, testId: 'bwx-screen-settings', admin: true },
];

/** The screens only an administrator opens (#406). A Manager is refused them by the server too. */
const ADMIN_ONLY = new Set< string >( RAIL.flatMap( ( entry ) => ( 'key' in entry && entry.admin ? [ entry.key ] : [] ) ) );

/** The rail somebody sees: a Manager's has no admin entries, and no group left empty by that. */
function railFor( admin: boolean ): Entry[] {
  const kept = RAIL.filter( ( entry ) => admin || ! ( 'admin' in entry && entry.admin ) );

  return kept.filter( ( entry, i ) => ! ( 'group' in entry ) || ( undefined !== kept[ i + 1 ] && ! ( 'group' in kept[ i + 1 ] ) ) );
}

/**
 * Whether an entry is on the phone's bottom bar (#428) — and so left out of
 * the phone's menu. A group heading is, when everything under it is.
 */
function onTabBar( rail: Entry[], i: number ): boolean {
  const entry = rail[ i ];

  if ( ! ( 'group' in entry ) ) {
    return 'primary' in entry && true === entry.primary;
  }

  const under: Entry[] = [];
  for ( let next = i + 1; next < rail.length && ! ( 'group' in rail[ next ] ); next += 1 ) {
    under.push( rail[ next ] );
  }

  return under.every( ( one ) => 'primary' in one && true === one.primary );
}

/**
 * When the screen's data last came from the server, and a way to ask again.
 *
 * The time is the newest answer the app holds (see api.ts): a screen's own
 * reads are the newest thing that has happened, so this is when what is on
 * screen was fetched. It moves when a background re-check completes, whether
 * or not it found anything. The button forgets everything kept and remounts
 * the screen, so what follows is a real read of every path it uses.
 */
/**
 * Who is signed in, top right of every screen, by name; it opens their
 * profile screen, where their own settings live (2026-09-18).
 */
function Profile( { onOpen, testId = 'bwx-profile' }: { onOpen: () => void; testId?: string } ) {
  const data = forgeData();
  const who = data?.currentUser;

  if ( ! who ) {
    return null;
  }

  return (
    <a
      className="fs-profile"
      href="#screen=profile"
      data-testid={ testId }
      title="Your profile"
      onClick={ ( event ) => {
        event.preventDefault();
        onOpen();
      } }
    >
      <Avatar name={ who.name } />
      <span className="fs-profile-name" data-testid={ `${ testId }-name` }>{ who.name }</span>
    </a>
  );
}

function Refreshed( { onRefresh }: { onRefresh: () => void } ) {
  const [ at, setAt ] = useState( refreshedAt() );

  useEffect( () => onRefreshed( () => setAt( refreshedAt() ) ), [] );

  const when = 0 === at ? '' : new Date( at ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );

  return (
    <span className="fs-refreshed" data-testid="bwx-refreshed" data-at={ at }>
      { '' !== when && <span className="fk-mono">Refreshed { when }</span> }
      <Button
        variant="secondary"
        size="sm"
        data-testid="bwx-refresh-all"
        aria-label="Refresh"
        title="Read everything on this screen again"
        onClick={ () => {
          forgetAll();
          onRefresh();
        } }
      >
        <RefreshCw size={ 14 } aria-hidden="true" />
      </Button>
    </span>
  );
}

const TITLES: Record< ScreenName, string > = {
  mytasks: 'My tasks',
  work: 'Kanban',
  requests: 'Requests review',
  capacity: 'Capacity',
  onboarding: 'Onboarding board',
  standup: 'Daily standup',
  reports: 'Reports',
  recurring: 'Recurring tasks',
  reminders: 'Reminders',
  subscriptions: 'Subscriptions',
  availability: 'Availability',
  packages: 'Support packages',
  settings: 'Settings',
  people: 'People',
  clients: 'Clients',
  support: 'Support',
  meetings: 'Meetings',
  profile: 'Your profile',
};

const VIEW_TITLES: Partial< Record< ViewName, string > > = {
  board: 'Kanban',
  list: 'Work list',
  gantt: 'Gantt',
  calendar: 'Calendar',
};

interface Opening {
  crumbs: string[];
  eyebrow: string;
  tile: LucideIcon;
  hue: TileHue;
}

/** How each screen opens — the design's page header, drawn by the shell. */
const OPENINGS: Record< ScreenName | 'gantt' | 'calendar', Opening > = {
  mytasks: { crumbs: [ 'My day', 'My tasks' ], eyebrow: 'Your day and week', tile: ListChecks, hue: 'blue' },
  work: { crumbs: [ 'Delivery', 'Kanban' ], eyebrow: 'Controlled workflow', tile: Columns3, hue: 'teal' },
  gantt: { crumbs: [ 'Delivery', 'Gantt' ], eyebrow: 'Schedule', tile: GanttChart, hue: 'violet' },
  calendar: { crumbs: [ 'Delivery', 'Calendar' ], eyebrow: 'Key dates', tile: CalendarDays, hue: 'violet' },
  capacity: { crumbs: [ 'Delivery', 'Capacity' ], eyebrow: 'Who has room', tile: Gauge, hue: 'teal' },
  requests: { crumbs: [ 'Intake', 'Requests review' ], eyebrow: 'Cross-client intake', tile: Inbox, hue: 'amber' },
  onboarding: { crumbs: [ 'Clients', 'Onboarding board' ], eyebrow: 'New-client setup', tile: FileCheck2, hue: 'blue' },
  standup: { crumbs: [ 'My day', 'Daily standup' ], eyebrow: 'Today', tile: Clock, hue: 'blue' },
  reports: { crumbs: [ 'Insight', 'Reports' ], eyebrow: 'How delivery is going', tile: BarChart3, hue: 'slate' },
  recurring: { crumbs: [ 'Delivery', 'Recurring tasks' ], eyebrow: 'Every day, week or month', tile: Repeat, hue: 'teal' },
  reminders: { crumbs: [ 'Delivery', 'Reminders' ], eyebrow: 'On a day, or over a few', tile: Bell, hue: 'teal' },
  subscriptions: { crumbs: [ 'Insight', 'Subscriptions' ], eyebrow: 'What renews, and when', tile: CreditCard, hue: 'slate' },
  availability: { crumbs: [ 'Team', 'Availability' ], eyebrow: 'Working weeks and time off', tile: CalendarCheck, hue: 'blue' },
  packages: { crumbs: [ 'Insight', 'Packages' ], eyebrow: 'What is on offer, and every version of it', tile: Receipt, hue: 'emerald' },
  settings: { crumbs: [ 'Insight', 'Settings' ], eyebrow: 'How Forge behaves, and why', tile: ScrollText, hue: 'slate' },
  people: { crumbs: [ 'Team', 'People' ], eyebrow: 'Everyone, and everywhere they work', tile: Users, hue: 'violet' },
  clients: { crumbs: [ 'Clients', 'Clients' ], eyebrow: 'Who we work for, and their sites', tile: Building2, hue: 'teal' },
  support: { crumbs: [ 'Clients', 'Support' ], eyebrow: 'What each site is on, and the hours it has', tile: LifeBuoy, hue: 'amber' },
  meetings: { crumbs: [ 'Clients', 'Meetings' ], eyebrow: 'Standing meetings, and the next twelve weeks of them', tile: CalendarClock, hue: 'rose' },
  profile: { crumbs: [ 'You', 'Profile' ], eyebrow: 'Your Slack, working week and time off', tile: CircleUser, hue: 'violet' },
};

/** How many requests are waiting on the studio — the rail's one live count. */
function useRequestsWaiting( screen: ScreenName ): number | null {
  const [ waiting, setWaiting ] = useState< number | null >( null );

  useEffect( () => {
    let live = true;
    api< { submissions: Array< { intake_state: string } > } >( '/submissions' )
      .then( ( answer ) => {
        if ( live ) setWaiting( answer.submissions.filter( ( s ) => [ 'received', 'in-review' ].includes( s.intake_state ) ).length );
      } )
      .catch( () => {
        if ( live ) setWaiting( null );
      } );
    return () => {
      live = false;
    };
    // Re-read whenever the screen changes: leaving the queue is when the
    // count is most likely to have moved.
  }, [ screen ] );

  return waiting;
}

/**
 * Holds the screen back until the Client picker has settled (#402), so no
 * screen ever reads one client and then another.
 */
function WhenChosen( { children }: { children: ReactNode } ) {
  const { ready } = useClientChoice();

  return ready ? <>{ children }</> : <Screen state="loading" detail="Reading your clients." />;
}

export function App() {
  const data = forgeData();
  const admin = data?.canManage ?? false;
  /**
   * A link can land on a screen: from Slack on the task in the hash (PR 5),
   * or from anywhere on a screen and, for availability and people, a person,
   * or, for support and meetings, a site. Read once and cleared, so a reload is a plain
   * reload.
   */
  const [ landing ] = useState( () => {
    const hash = window.location.hash;
    const item = /(?:^|[#&])item=([A-Za-z0-9_-]+)/.exec( hash )?.[ 1 ] ?? '';
    const screen = /(?:^|[#&])screen=([a-z]+)/.exec( hash )?.[ 1 ] ?? '';
    const person = /(?:^|[#&])person=([A-Za-z0-9_-]+)/.exec( hash )?.[ 1 ] ?? '';
    const site = /(?:^|[#&])site=([A-Za-z0-9_-]+)/.exec( hash )?.[ 1 ] ?? '';

    if ( '' !== item || '' !== screen ) {
      window.history.replaceState( null, '', window.location.pathname + window.location.search );
    }

    // A Manager landing on an administrator's screen opens the board instead.
    const allowed = Object.hasOwn( TITLES, screen ) && ( admin || ! ADMIN_ONLY.has( screen ) );

    return { item, screen: allowed ? ( screen as ScreenName ) : null, person, site };
  } );
  const [ screen, setScreen ] = useState< ScreenName >( landing.screen ?? 'work' );
  const [ view, setView ] = useState< ViewName >( 'board' );
  /*
   * "New task" asks the board to open its add form; the board says when it
   * has, and the ask is over (#452). A count kept for good would be re-read
   * as a fresh ask by every board opened afterwards.
   */
  const [ newWorkAsked, setNewWorkAsked ] = useState( false );
  const newWorkOpened = () => setNewWorkAsked( false );
  const [ generation, setGeneration ] = useState( 0 );
  const waiting = useRequestsWaiting( screen );
  /*
   * On a phone (#428) the rail is a side menu, opened from the bottom bar.
   * Whether it is open only matters while the shell is compact.
   */
  const compact = useCompact();
  const [ menuOpen, setMenuOpen ] = useState( false );
  const menuButton = useRef< HTMLButtonElement >( null );
  const menuClose = useRef< HTMLButtonElement >( null );
  const railRef = useRef< HTMLElement >( null );
  const wasOpen = useRef( false );
  /*
   * Whether the menu slides (#442): only when somebody opens or closes it.
   * A window narrowed to a phone puts the rail away without it sliding across.
   */
  const [ motion, setMotion ] = useState( false );

  useEffect( () => {
    if ( compact && menuOpen ) {
      // Opening the menu takes focus into it, so a keyboard user follows it.
      menuClose.current?.focus();
    } else if ( compact && wasOpen.current ) {
      // Closed: back to the Menu button, once the bar behind is reachable again.
      menuButton.current?.focus();
    } else if ( ! compact && wasOpen.current ) {
      // Widened with it open (#441): the close button has gone, so focus
      // moves to where the rail says you are rather than falling to the page.
      railRef.current?.querySelector< HTMLElement >( '[aria-current="page"], button' )?.focus();
    }
    wasOpen.current = compact && menuOpen;
  }, [ compact, menuOpen ] );

  // Crossing between a phone and a desktop: the rail is the rail again, and
  // narrowed back it starts closed, without sliding. Done when the window
  // changes, not while drawing: a state change made mid-draw stopped React
  // hearing the next change, and the bottom bar never came back (2026-09-28).
  useEffect(
    () =>
      onCompactChange( () => {
        setMenuOpen( false );
        setMotion( false );
      } ),
    []
  );

  if ( ! isConnected() ) {
    return (
      <main className="bwx-app" data-testid="bwx-forge-ready">
        <div style={ { margin: 'auto', textAlign: 'center' } }>
          <h1 className="bwx-wordmark">
            Blueworx <span>Forge</span>
          </h1>
          <Screen
            state="empty"
            title="Running outside WordPress"
            detail="There is no server to read work from, so the board has nothing to draw."
          />
        </div>
      </main>
    );
  }

  const adminUrl = data?.adminUrl ?? `${ data?.siteUrl ?? '' }/wp-admin/`;
  const title = 'work' === screen ? VIEW_TITLES[ view ] ?? TITLES.work : TITLES[ screen ];
  const opening = OPENINGS[ 'work' === screen && ( 'gantt' === view || 'calendar' === view ) ? view : screen ];

  const closeMenu = () => {
    setMotion( true );
    setMenuOpen( false );
  };

  const openMenu = () => {
    setMotion( true );
    setMenuOpen( true );
  };

  // While the menu is open the screen and the bottom bar behind it are out of
  // reach (#443): not tabbable, and not found by a screen reader's cursor.
  const shut = compact && menuOpen ? { inert: '' } : {};

  const go = ( key: ScreenName, next?: ViewName ) => {
    setScreen( key );
    if ( next ) setView( next );
    if ( menuOpen ) closeMenu();
  };

  /** Keeps Tab inside the open menu, and lets Escape close it — the kit Modal's pattern. */
  const onMenuKey = ( event: KeyboardEvent< HTMLElement > ) => {
    if ( ! compact || ! menuOpen ) return;

    if ( 'Escape' === event.key ) {
      closeMenu();
      return;
    }

    if ( 'Tab' !== event.key || ! railRef.current ) return;

    const focusable = Array.from( railRef.current.querySelectorAll< HTMLElement >( 'button, a[href], select' ) ).filter( ( el ) => null !== el.offsetParent );
    if ( 0 === focusable.length ) return;

    const first = focusable[ 0 ];
    const last = focusable[ focusable.length - 1 ];
    if ( event.shiftKey && document.activeElement === first ) {
      event.preventDefault();
      last.focus();
    } else if ( ! event.shiftKey && document.activeElement === last ) {
      event.preventDefault();
      first.focus();
    }
  };

  const rail = railFor( admin );

  const isCurrent = ( entry: Entry ): boolean => {
    if ( ! ( 'key' in entry ) || entry.key !== screen ) return false;
    if ( 'work' !== entry.key ) return true;
    // The list is a way of reading the board, so the Kanban entry stands for both.
    return entry.view === view || ( 'board' === entry.view && 'list' === view );
  };

  return (
    <ClientChoiceProvider landing={ landing.site }>
    <div className="fs-shell" data-testid="bwx-forge-ready">
      <nav className="fs-rail" aria-label="Screens" id="bwx-menu" ref={ railRef } data-open={ menuOpen ? 'true' : undefined } data-motion={ motion ? 'true' : undefined } onKeyDown={ onMenuKey }>
        <div className="fs-rail-brand">
          <span className="fs-mark" aria-hidden="true">
            F
          </span>
          <span className="fs-rail-brand-text">
            <span className="fs-rail-brand-name">Forge</span>
            <span className="fs-rail-brand-sub">Command centre</span>
          </span>
          { compact && (
            <button type="button" className="bwx-icon-button fs-rail-close" ref={ menuClose } data-testid="bwx-menu-close" aria-label="Close the menu" onClick={ closeMenu }>
              <X size={ 18 } strokeWidth={ 2 } aria-hidden="true" />
            </button>
          ) }
        </div>

        { /* What leaves the top bar on a phone (#428): New task, and who you are. */ }
        { compact && (
          <div className="fs-rail-extras">
            <Button
              variant="soft"
              size="sm"
              data-testid="bwx-menu-new-task"
              onClick={ () => {
                go( 'work', 'board' );
                setNewWorkAsked( true );
              } }
            >
              New task
            </Button>
            <Profile testId="bwx-menu-profile" onOpen={ () => go( 'profile' ) } />
          </div>
        ) }

        <div className="fs-rail-list">
          { rail.map( ( entry, i ) => {
            if ( 'group' in entry ) {
              return (
                <div key={ i } className="fs-rail-group" data-primary={ onTabBar( rail, i ) ? 'true' : undefined }>
                  { entry.group }
                </div>
              );
            }
            const Icon = entry.icon;
            if ( 'href' in entry ) {
              return (
                <a key={ entry.testId } className="fs-rail-item" href={ `${ adminUrl }${ entry.href }` } data-testid={ entry.testId }>
                  <Icon size={ 18 } strokeWidth={ 1.5 } aria-hidden="true" />
                  <span className="fs-rail-label">{ entry.label }</span>
                  <ExternalLink size={ 12 } strokeWidth={ 1.5 } aria-hidden="true" className="fs-rail-ext" />
                  <span className="screen-reader-text"> (WordPress admin)</span>
                </a>
              );
            }
            const current = isCurrent( entry );
            const count = 'requests' === entry.key && waiting ? waiting : null;
            return (
              <button
                key={ entry.testId }
                type="button"
                className="fs-rail-item"
                aria-current={ current ? 'page' : undefined }
                aria-pressed={ current }
                data-testid={ entry.testId }
                data-primary={ onTabBar( rail, i ) ? 'true' : undefined }
                onClick={ () => go( entry.key, entry.view ) }
              >
                <Icon size={ 18 } strokeWidth={ 1.5 } aria-hidden="true" />
                <span className="fs-rail-label">{ entry.label }</span>
                { null !== count && (
                  <span className="fs-rail-count fk-mono" data-alert="true" aria-label={ `${ count } waiting` }>
                    { count }
                  </span>
                ) }
                <RailCount name={ entry.key } screen={ screen } />
              </button>
            );
          } ) }
        </div>

        <div className="fs-rail-foot">
          <span className="fs-rail-foot-name">Blueworx Forge</span>
          <span className="bwx-mono">v{ data?.version ?? '' }</span>
        </div>
      </nav>

      <main className="bwx-app fs-main" { ...shut }>
        <div className="fs-topbar bwx-shellbar">
          <span className="fs-title">{ title }</span>
          <span className="bwx-header-spacer" />
          <ClientPicker />
          <Signals />
          <span className="fs-topbar-new">
            <Button
              variant="soft"
              size="sm"
              data-testid="bwx-new-task"
              onClick={ () => {
                // From anywhere: the form lives on the board, so go there.
                setScreen( 'work' );
                setView( 'board' );
                setNewWorkAsked( true );
              } }
            >
              New task
            </Button>
          </span>
          <span className="fs-topbar-you">
            <span className="fs-topbar-divider" aria-hidden="true" />
            <Profile onOpen={ () => setScreen( 'profile' ) } />
          </span>
        </div>

        { /*
           Each screen is mounted rather than hidden, so switching away drops its
           state and switching back reads fresh. A queue kept alive in the
           background is a queue showing answers somebody else gave ten minutes
           ago. The work screen stays mounted across its three rail entries,
           because they are one screen.
         */ }
        <PageHeader
          crumbs={ opening.crumbs }
          eyebrow={ opening.eyebrow }
          title={ title }
          tile={ opening.tile }
          hue={ opening.hue }
          actions={ <Refreshed onRefresh={ () => setGeneration( ( n ) => n + 1 ) } /> }
        />

        { /*
           The key is the header's refresh button: bumping it remounts the
           screen, which reads afresh because the cache was just emptied. The
           same thing switching screens does, on demand.
         */ }
        <WhenChosen>
        { 'mytasks' === screen && <MyTasksScreen key={ generation } /> }
        { 'work' === screen && <WorkScreen key={ generation } view={ view } onViewChange={ setView } newWorkAsked={ newWorkAsked } onNewWorkOpened={ newWorkOpened } openItem={ landing.item } /> }
        { 'requests' === screen && <QueueScreen key={ generation } /> }
        { 'capacity' === screen && <CapacityScreen key={ generation } /> }
        { 'onboarding' === screen && <OnboardingScreen key={ generation } /> }
        { 'standup' === screen && <StandupScreen key={ generation } /> }
        { 'reports' === screen && <ReportsScreen key={ generation } /> }
        { 'recurring' === screen && <RecurringScreen key={ generation } /> }
        { 'reminders' === screen && <RemindersScreen key={ generation } /> }
        { 'subscriptions' === screen && <SubscriptionsScreen key={ generation } /> }
        { 'availability' === screen && <AvailabilityScreen key={ generation } person={ landing.person } /> }
        { 'packages' === screen && <PackagesScreen key={ generation } /> }
        { 'settings' === screen && <SettingsScreen key={ generation } /> }
        { 'people' === screen && <PeopleScreen key={ generation } person={ landing.person } /> }
        { 'clients' === screen && <ClientsScreen key={ generation } /> }
        { 'support' === screen && <SupportScreen key={ generation } /> }
        { 'meetings' === screen && <MeetingsScreen key={ generation } /> }
        { 'profile' === screen && <ProfileScreen key={ generation } /> }
        </WhenChosen>
      </main>

      { compact && menuOpen && <div className="fs-menu-scrim" data-testid="bwx-menu-scrim" onClick={ closeMenu } /> }
      { compact && (
        <TabBar
          screen={ screen }
          view={ view }
          waiting={ waiting }
          menuOpen={ menuOpen }
          menuRef={ menuButton }
          onPick={ go }
          onMenu={ () => ( menuOpen ? closeMenu() : openMenu() ) }
          shut={ compact && menuOpen }
        />
      ) }
    </div>
    </ClientChoiceProvider>
  );
}
