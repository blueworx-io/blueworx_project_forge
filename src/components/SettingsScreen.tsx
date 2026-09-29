import type { MouseEvent } from 'react';
import { Panel } from '../kit';
import { RULES } from '../rules';

/**
 * Every rule Forge follows, in plain English (#467): what happens, and why.
 *
 * Read-only by design. The rules are written in src/rules.ts, and a change to
 * how Forge behaves changes that file in the same pull request.
 */
export function SettingsScreen() {
  /*
   * Scrolled to rather than followed: the app reads #screen= from the address
   * on load, and a bare #rules-x left there would be a link to nowhere.
   */
  const jump = ( event: MouseEvent< HTMLAnchorElement >, id: string ) => {
    event.preventDefault();
    document.getElementById( `rules-${ id }` )?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
  };

  return (
    <div className="bwx-settings" data-testid="bwx-settings">
      <p className="bwx-hint">How Forge behaves, and why. Nothing here can be changed from this page.</p>

      <nav aria-label="Rule sections" className="bwx-rules-jump" data-testid="bwx-rules-jump">
        { RULES.map( ( section ) => (
          <a key={ section.id } href={ `#rules-${ section.id }` } onClick={ ( event ) => jump( event, section.id ) }>
            { section.title }
          </a>
        ) ) }
      </nav>

      { RULES.map( ( section ) => (
        <div key={ section.id } id={ `rules-${ section.id }` } className="bwx-rules-section" data-testid={ `bwx-rules-${ section.id }` }>
          <Panel title={ section.title }>
            <ul className="bwx-rules">
              { section.rules.map( ( rule ) => (
                <li key={ rule.what }>
                  <p className="bwx-rule-what">{ rule.what }</p>
                  <p className="bwx-hint">{ rule.why }</p>
                </li>
              ) ) }
            </ul>
          </Panel>
        </div>
      ) ) }
    </div>
  );
}
