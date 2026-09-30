import { useCallback, useEffect, useState } from 'react';
import type { ScreenName, StandupList, WorkItem } from './types';
import { api, forgeData } from './api';
import { useClientChoice } from './ClientChoice';
import { useLiveReload } from './live';
import { todayCount, mineFor } from './mytasks';
import type { Mine, Site } from './mytasks';
import { standupCount } from './standup';

/*
 * The sidebar's counts on My tasks and Daily standup (#450). Each reads what
 * its screen reads and counts it with the screen's own functions, for the
 * client the top bar has picked, so the number cannot differ from the screen.
 * Re-read whenever the screen changes: leaving one is when its count has most
 * likely moved.
 */

function useMyTasksCount( screen: ScreenName ): number | null {
  const { siteId } = useClientChoice();
  const me = forgeData()?.person ?? null;
  const [ mine, setMine ] = useState< Mine[] | null >( null );

  const load = useCallback( async () => {
    if ( ! me ) return;
    try {
      const [ siteList, loaded ] = await Promise.all( [ api< { sites: Site[] } >( '/client-sites' ), api< { items: WorkItem[] } >( '/work-items-all' ) ] );
      setMine( mineFor( me, siteList.sites, loaded.items ) );
    } catch {
      setMine( null );
    }
  }, [ me ] );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load, screen ] );
  useLiveReload( load );

  return null === mine ? null : todayCount( mine, siteId );
}

function useStandupCount( screen: ScreenName ): number | null {
  const { siteId } = useClientChoice();
  const [ list, setList ] = useState< StandupList | null >( null );

  const load = useCallback( async () => {
    try {
      const answer = await api< StandupList >( '/standup' );
      setList( answer.denied ? null : answer );
    } catch {
      setList( null );
    }
  }, [] );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load, screen ] );
  useLiveReload( load );

  return null === list ? null : standupCount( list, siteId );
}

function Count( { count, said }: { count: number | null; said: string } ) {
  if ( ! count ) return null;
  return (
    <span className="fs-rail-count fk-mono" data-alert="true" aria-label={ `${ count } ${ said }` }>
      { count }
    </span>
  );
}

/** The count beside a screen's name in the rail or the tab bar, if it has one. */
export function RailCount( { name, screen }: { name: ScreenName; screen: ScreenName } ) {
  if ( 'mytasks' === name ) return <MyTasksBadge screen={ screen } />;
  if ( 'standup' === name ) return <StandupBadge screen={ screen } />;
  return null;
}

function MyTasksBadge( { screen }: { screen: ScreenName } ) {
  return <Count count={ useMyTasksCount( screen ) } said="for today" />;
}

function StandupBadge( { screen }: { screen: ScreenName } ) {
  return <Count count={ useStandupCount( screen ) } said="needing attention" />;
}
