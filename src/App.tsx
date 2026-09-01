import { useState } from 'react';
import type { ScreenName } from './types';
import { forgeData, isConnected } from './api';
import { CapacityScreen } from './components/CapacityScreen';
import { OnboardingScreen } from './components/OnboardingScreen';
import { QueueScreen } from './components/QueueScreen';
import { Signals } from './components/Signals';
import { StandupScreen } from './components/StandupScreen';
import { Screen } from './components/States';
import { WorkScreen } from './components/WorkScreen';

/**
 * The studio application shell.
 *
 * Thin on purpose. It owns the four things that are true whichever screen is
 * open — the wordmark, which screen that is, what has happened lately, and the
 * version — and nothing else. Each screen brings its own header controls,
 * because a site picker means something on one of them and nothing on the
 * other.
 *
 * What has happened lately (#175) is up here rather than being a sixth screen
 * because a request arriving, or your work coming back, matters the same amount
 * whichever screen somebody is looking at — and nobody goes anywhere to check
 * whether something happened.
 *
 * There are separate screens rather than more views for the same reason (#131).
 * The board, list, schedule and calendar are four ways of drawing one site's
 * work, and they share a filter set and a site. The request queue, capacity and
 * onboarding each span clients and share neither. Making any of them a fifth
 * view would have put a site picker and six inert work filters above a list
 * they do not apply to.
 */
export function App() {
  const data = forgeData();
  const [ screen, setScreen ] = useState< ScreenName >( 'work' );

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

  return (
    <main className="bwx-app" data-testid="bwx-forge-ready">
      <div className="bwx-shellbar">
        <h1 className="bwx-wordmark">
          Blueworx <span>Forge</span>
        </h1>

        <div className="bwx-views" role="group" aria-label="Screen">
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'work' === screen ? undefined : 'quiet' }
            data-testid="bwx-screen-work"
            aria-pressed={ 'work' === screen }
            onClick={ () => setScreen( 'work' ) }
          >
            Work
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'requests' === screen ? undefined : 'quiet' }
            data-testid="bwx-screen-requests"
            aria-pressed={ 'requests' === screen }
            onClick={ () => setScreen( 'requests' ) }
          >
            Requests
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'capacity' === screen ? undefined : 'quiet' }
            data-testid="bwx-screen-capacity"
            aria-pressed={ 'capacity' === screen }
            onClick={ () => setScreen( 'capacity' ) }
          >
            Capacity
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'onboarding' === screen ? undefined : 'quiet' }
            data-testid="bwx-screen-onboarding"
            aria-pressed={ 'onboarding' === screen }
            onClick={ () => setScreen( 'onboarding' ) }
          >
            Onboarding
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'standup' === screen ? undefined : 'quiet' }
            data-testid="bwx-screen-standup"
            aria-pressed={ 'standup' === screen }
            onClick={ () => setScreen( 'standup' ) }
          >
            Today
          </button>
        </div>

        <span className="bwx-header-spacer" />

        <Signals />

        <span className="bwx-mono">v{ data?.version ?? '' }</span>
      </div>

      { /*
         Each screen is mounted rather than hidden, so switching away drops its
         state and switching back reads fresh. A queue kept alive in the
         background is a queue showing answers somebody else gave ten minutes
         ago.
       */ }
      { 'work' === screen && <WorkScreen /> }
      { 'requests' === screen && <QueueScreen /> }
      { 'capacity' === screen && <CapacityScreen /> }
      { 'onboarding' === screen && <OnboardingScreen /> }
      { 'standup' === screen && <StandupScreen /> }
    </main>
  );
}
