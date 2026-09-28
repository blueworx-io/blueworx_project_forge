import type { RefObject } from 'react';
import type { LucideIcon } from 'lucide-react';
import { CalendarDays, Clock, Inbox, ListChecks, Menu } from 'lucide-react';
import type { ScreenName, ViewName } from '../types';

interface Tab {
  key: ScreenName;
  view?: ViewName;
  label: string;
  icon: LucideIcon;
  testId: string;
}

/** The four screens somebody opens every day (2026-09-27, #428), one tap away on a phone. */
const TABS: Tab[] = [
  { key: 'mytasks', label: 'My tasks', icon: ListChecks, testId: 'bwx-tab-mytasks' },
  { key: 'standup', label: 'Standup', icon: Clock, testId: 'bwx-tab-standup' },
  { key: 'requests', label: 'Requests', icon: Inbox, testId: 'bwx-tab-requests' },
  { key: 'work', view: 'calendar', label: 'Calendar', icon: CalendarDays, testId: 'bwx-tab-calendar' },
];

/**
 * The bar along the bottom of a phone: the four daily screens, then Menu for
 * everything else. Only drawn when the shell is compact, so its testids never
 * sit beside the rail's on a desktop.
 */
export function TabBar( {
  screen,
  view,
  waiting,
  menuOpen,
  menuRef,
  onPick,
  onMenu,
  shut,
}: {
  screen: ScreenName;
  view: ViewName;
  waiting: number | null;
  menuOpen: boolean;
  menuRef: RefObject< HTMLButtonElement >;
  onPick: ( key: ScreenName, view?: ViewName ) => void;
  onMenu: () => void;
  /** The menu is open over it (#443): out of reach until it closes. */
  shut: boolean;
} ) {
  return (
    <nav className="fs-tabbar" aria-label="Quick screens" data-testid="bwx-tabbar" { ...( shut ? { inert: '' } : {} ) }>
      { TABS.map( ( tab ) => {
        const Icon = tab.icon;
        const current = tab.key === screen && ( ! tab.view || tab.view === view );
        const count = 'requests' === tab.key && waiting ? waiting : null;
        return (
          <button
            key={ tab.testId }
            type="button"
            className="fs-tab"
            data-testid={ tab.testId }
            aria-current={ current ? 'page' : undefined }
            onClick={ () => onPick( tab.key, tab.view ) }
          >
            <Icon size={ 22 } strokeWidth={ 1.5 } aria-hidden="true" />
            <span>{ tab.label }</span>
            { null !== count && (
              <span className="fs-rail-count fk-mono" data-alert="true" aria-label={ `${ count } waiting` }>
                { count }
              </span>
            ) }
          </button>
        );
      } ) }
      <button
        type="button"
        className="fs-tab"
        ref={ menuRef }
        data-testid="bwx-menu-toggle"
        aria-expanded={ menuOpen }
        aria-controls="bwx-menu"
        onClick={ onMenu }
      >
        <Menu size={ 22 } strokeWidth={ 1.5 } aria-hidden="true" />
        <span>Menu</span>
      </button>
    </nav>
  );
}
