import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Panel } from '../kit';
import { RULES } from '../rules';

/**
 * Every rule Forge follows, in plain English (#467): what happens, and why.
 * One topic at a time (#528): the topics are tabs, and only the chosen one's
 * rules are on the page.
 *
 * Read-only by design. The rules are written in src/rules.ts, and a change to
 * how Forge behaves changes that file in the same pull request.
 */
export function SettingsScreen( { section = '' }: { section?: string } ) {
  const known = RULES.some( ( one ) => one.id === section );
  const [ active, setActive ] = useState( known ? section : RULES[ 0 ].id );
  const tabs = useRef< Map< string, HTMLButtonElement > >( new Map() );

  const shown = RULES.find( ( one ) => one.id === active ) ?? RULES[ 0 ];

  /** Arrow keys move between the tabs, as a tab list is expected to. */
  const onKey = ( event: KeyboardEvent< HTMLButtonElement >, index: number ) => {
    const step = { ArrowRight: 1, ArrowLeft: -1, Home: -index, End: RULES.length - 1 - index }[ event.key ];

    if ( undefined === step ) {
      return;
    }

    event.preventDefault();
    const next = RULES[ ( index + step + RULES.length ) % RULES.length ];

    setActive( next.id );
    tabs.current.get( next.id )?.focus();
  };

  return (
    <div className="bwx-settings" data-testid="bwx-settings">
      <p className="bwx-hint">How Forge behaves, and why. Nothing here can be changed from this page.</p>

      <div className="fk-tabs bwx-rules-tabs" role="tablist" aria-label="Rule topics" data-testid="bwx-rules-tabs">
        { RULES.map( ( one, index ) => (
          <button
            key={ one.id }
            ref={ ( element ) => {
              if ( element ) {
                tabs.current.set( one.id, element );
              } else {
                tabs.current.delete( one.id );
              }
            } }
            type="button"
            role="tab"
            id={ `rules-tab-${ one.id }` }
            className="fk-tab"
            aria-selected={ one.id === active }
            aria-controls={ `rules-${ one.id }` }
            tabIndex={ one.id === active ? 0 : -1 }
            data-testid={ `bwx-rules-tab-${ one.id }` }
            onClick={ () => setActive( one.id ) }
            onKeyDown={ ( event ) => onKey( event, index ) }
          >
            { one.title }
          </button>
        ) ) }
      </div>

      <div
        key={ shown.id }
        id={ `rules-${ shown.id }` }
        role="tabpanel"
        aria-labelledby={ `rules-tab-${ shown.id }` }
        className="bwx-rules-section"
        data-testid={ `bwx-rules-${ shown.id }` }
      >
        <Panel title={ shown.title }>
          <ul className="bwx-rules">
            { shown.rules.map( ( rule ) => (
              <li key={ rule.what }>
                <p className="bwx-rule-what">{ rule.what }</p>
                <p className="bwx-hint">{ rule.why }</p>
              </li>
            ) ) }
          </ul>
        </Panel>
      </div>
    </div>
  );
}
