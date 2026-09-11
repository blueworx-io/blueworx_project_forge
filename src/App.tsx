import { useEffect, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { BarChart3, CalendarDays, Clock, Columns3, ExternalLink, FileCheck2, GanttChart, Gauge, Inbox, ListChecks, Receipt, RefreshCw } from 'lucide-react';
import type { ScreenName, ViewName } from './types';
import { api, forgeData, isConnected } from './api';
import { CapacityScreen } from './components/CapacityScreen';
import { MyTasksScreen } from './components/MyTasksScreen';
import { OnboardingScreen } from './components/OnboardingScreen';
import { QueueScreen } from './components/QueueScreen';
import { ReportsScreen } from './components/ReportsScreen';
import { Signals } from './components/Signals';
import { StandupScreen } from './components/StandupScreen';
import { Screen } from './components/States';
import { WorkScreen } from './components/WorkScreen';
import { Button, PageHeader } from './kit';
import type { TileHue } from './kit';
import './shell.css';

/**
 * The studio application shell (#304): a rail down the left with the screens
 * in groups, a bar across the top that says where you are, and the screen.
 *
 * The rail owns which screen is open and, for the work screen, which view —
 * Kanban, Gantt and Calendar are three entries that open one screen, so the
 * board's own view switch and the rail can never disagree. Each screen brings
 * its own header controls, because a site picker means something on one of
 * them and nothing on the other.
 *
 * What has happened lately (#175) stays in the top bar rather than being a
 * screen, because a request arriving, or your work coming back, matters the
 * same amount whichever screen somebody is looking at.
 *
 * Packages & hours and Sync health are still WordPress admin screens; the
 * rail links to them so nothing is further away than it was.
 */

type Entry =
  | { group: string }
  | { key: ScreenName; label: string; icon: LucideIcon; testId: string; view?: ViewName }
  | { href: string; label: string; icon: LucideIcon; testId: string };

const RAIL: Entry[] = [
  { group: 'My day' },
  { key: 'mytasks', label: 'My tasks', icon: ListChecks, testId: 'bwx-screen-mytasks' },
  { key: 'standup', label: 'Daily standup', icon: Clock, testId: 'bwx-screen-standup' },
  { group: 'Delivery' },
  { key: 'work', view: 'board', label: 'Kanban', icon: Columns3, testId: 'bwx-screen-work' },
  { key: 'work', view: 'gantt', label: 'Gantt', icon: GanttChart, testId: 'bwx-screen-gantt' },
  { key: 'work', view: 'calendar', label: 'Calendar', icon: CalendarDays, testId: 'bwx-screen-calendar' },
  { key: 'capacity', label: 'Capacity', icon: Gauge, testId: 'bwx-screen-capacity' },
  { group: 'Intake' },
  { key: 'requests', label: 'Requests review', icon: Inbox, testId: 'bwx-screen-requests' },
  { group: 'Clients' },
  { key: 'onboarding', label: 'Onboarding board', icon: FileCheck2, testId: 'bwx-screen-onboarding' },
  { href: 'admin.php?page=blueworx-forge-packages', label: 'Packages & hours', icon: Receipt, testId: 'bwx-link-packages' },
  { href: 'admin.php?page=blueworx-forge-sync', label: 'Sync health', icon: RefreshCw, testId: 'bwx-link-sync' },
  { group: 'Insight' },
  { key: 'reports', label: 'Reports', icon: BarChart3, testId: 'bwx-screen-reports' },
];

const TITLES: Record< ScreenName, string > = {
  mytasks: 'My tasks',
  work: 'Kanban',
  requests: 'Requests review',
  capacity: 'Capacity',
  onboarding: 'Onboarding board',
  standup: 'Daily standup',
  reports: 'Reports',
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
  description: string;
  tile: LucideIcon;
  hue: TileHue;
}

/** How each screen opens — the design's page header, drawn by the shell. */
const OPENINGS: Record< ScreenName | 'gantt' | 'calendar', Opening > = {
  mytasks: { crumbs: [ 'My day', 'My tasks' ], eyebrow: 'Your day and week', description: 'Every task that names you, on every client, from the same records as the board — so the counts always add up.', tile: ListChecks, hue: 'blue' },
  work: { crumbs: [ 'Delivery', 'Kanban' ], eyebrow: 'Controlled workflow', description: 'Twelve fixed stages. Work moves forward one stage at a time, and only once that stage’s checks are done.', tile: Columns3, hue: 'teal' },
  gantt: { crumbs: [ 'Delivery', 'Gantt' ], eyebrow: 'Schedule', description: 'The same records, the same filters and the same permissions as the board — only the shape changes.', tile: GanttChart, hue: 'violet' },
  calendar: { crumbs: [ 'Delivery', 'Calendar' ], eyebrow: 'Key dates', description: 'The same records, the same filters and the same permissions as the board — only the shape changes.', tile: CalendarDays, hue: 'violet' },
  capacity: { crumbs: [ 'Delivery', 'Capacity' ], eyebrow: 'Who has room', description: 'One person, one diary. Every job on every client counts once, so nobody looks free here and busy somewhere else.', tile: Gauge, hue: 'teal' },
  requests: { crumbs: [ 'Intake', 'Requests review' ], eyebrow: 'Cross-client intake', description: 'Bugs, requests, ideas and suggestions from every client. Submissions are immutable source records; a decision always records a reason.', tile: Inbox, hue: 'amber' },
  onboarding: { crumbs: [ 'Clients', 'Onboarding board' ], eyebrow: 'New-client setup', description: 'Every launch step for every client, who owns it, and what is still waiting on the client. A site cannot be marked Released while a launch-critical step is open.', tile: FileCheck2, hue: 'blue' },
  standup: { crumbs: [ 'My day', 'Daily standup' ], eyebrow: 'Today', description: 'A working list, not another board. Things leave it when the problem behind them is fixed.', tile: Clock, hue: 'blue' },
  reports: { crumbs: [ 'Insight', 'Reports' ], eyebrow: 'How delivery is going', description: 'The figures behind the boards, for every client at once.', tile: BarChart3, hue: 'slate' },
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

export function App() {
  const data = forgeData();
  const [ screen, setScreen ] = useState< ScreenName >( 'work' );
  const [ view, setView ] = useState< ViewName >( 'board' );
  const [ newWorkAsked, setNewWorkAsked ] = useState( 0 );
  const waiting = useRequestsWaiting( screen );

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

  const isCurrent = ( entry: Entry ): boolean => {
    if ( ! ( 'key' in entry ) || entry.key !== screen ) return false;
    if ( 'work' !== entry.key ) return true;
    // The list is a way of reading the board, so the Kanban entry stands for both.
    return entry.view === view || ( 'board' === entry.view && 'list' === view );
  };

  return (
    <div className="fs-shell" data-testid="bwx-forge-ready">
      <nav className="fs-rail" aria-label="Screens">
        <div className="fs-rail-brand">
          <span className="fs-mark" aria-hidden="true">
            F
          </span>
          <span className="fs-rail-brand-text">
            <span className="fs-rail-brand-name">Forge</span>
            <span className="fs-rail-brand-sub">Command centre</span>
          </span>
        </div>

        <div className="fs-rail-list">
          { RAIL.map( ( entry, i ) => {
            if ( 'group' in entry ) {
              return (
                <div key={ i } className="fs-rail-group">
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
                onClick={ () => {
                  setScreen( entry.key );
                  if ( entry.view ) setView( entry.view );
                } }
              >
                <Icon size={ 18 } strokeWidth={ 1.5 } aria-hidden="true" />
                <span className="fs-rail-label">{ entry.label }</span>
                { null !== count && (
                  <span className="fs-rail-count fk-mono" data-alert="true" aria-label={ `${ count } waiting` }>
                    { count }
                  </span>
                ) }
              </button>
            );
          } ) }
        </div>

        <div className="fs-rail-foot">
          <span className="fs-rail-foot-name">Blueworx Forge</span>
          <span className="bwx-mono">v{ data?.version ?? '' }</span>
        </div>
      </nav>

      <main className="bwx-app fs-main">
        <div className="fs-topbar bwx-shellbar">
          <span className="fs-title">{ title }</span>
          <span className="bwx-header-spacer" />
          <Signals />
          <Button
            variant="soft"
            size="sm"
            data-testid="bwx-new-task"
            onClick={ () => {
              // From anywhere: the form lives on the board, so go there.
              setScreen( 'work' );
              setView( 'board' );
              setNewWorkAsked( ( n ) => n + 1 );
            } }
          >
            New task
          </Button>
        </div>

        { /*
           Each screen is mounted rather than hidden, so switching away drops its
           state and switching back reads fresh. A queue kept alive in the
           background is a queue showing answers somebody else gave ten minutes
           ago. The work screen stays mounted across its three rail entries,
           because they are one screen.
         */ }
        <PageHeader crumbs={ opening.crumbs } eyebrow={ opening.eyebrow } title={ title } description={ opening.description } tile={ opening.tile } hue={ opening.hue } />

        { 'mytasks' === screen && <MyTasksScreen /> }
        { 'work' === screen && <WorkScreen view={ view } onViewChange={ setView } newWorkAsked={ newWorkAsked } /> }
        { 'requests' === screen && <QueueScreen /> }
        { 'capacity' === screen && <CapacityScreen /> }
        { 'onboarding' === screen && <OnboardingScreen /> }
        { 'standup' === screen && <StandupScreen /> }
        { 'reports' === screen && <ReportsScreen /> }
      </main>
    </div>
  );
}
