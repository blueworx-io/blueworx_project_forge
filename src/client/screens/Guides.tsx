import { BookOpen } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, EmptyState } from '../../kit';
import { clientData } from '../data';

/*
 * How Forge works, for the client (#580). The words are written once, in
 * the client plugin (Guides.php), and handed over with the page: the same
 * guides appear on the BlueWorx Guides page, which a Forge: Manager cannot
 * open, so this is where most clients read them.
 */

/** Turns *label* into emphasis. Everything else stays plain text. */
function emphasise( text: string ): ReactNode[] {
  return text.split( /\*([^*]+)\*/ ).map( ( part, index ) => ( index % 2 ? <em key={ index }>{ part }</em> : part ) );
}

export function Guides() {
  const guides = clientData()?.guides;

  if ( ! guides || 0 === guides.guides.length ) {
    return (
      <Card>
        <EmptyState icon={ BookOpen } title="No guides yet" body="The guides will appear here once this site has them." />
      </Card>
    );
  }

  return (
    <div className="fc-guides" data-testid="bwx-guides">
      { guides.tabs.map( ( tab ) => {
        const inTab = guides.guides.filter( ( guide ) => guide.tab === tab.id );

        if ( 0 === inTab.length ) {
          return null;
        }

        return (
          <section key={ tab.id } className="fc-guide-group" aria-labelledby={ `fc-guide-tab-${ tab.id }` }>
            <h2 id={ `fc-guide-tab-${ tab.id }` } className="fc-h3">
              { tab.label }
            </h2>
            { inTab.map( ( guide ) => (
              <Card key={ guide.id } testId={ `bwx-guide-${ guide.id }` }>
                <h3 className="fc-h3">{ guide.title }</h3>
                <p className="fc-muted">Where: { emphasise( guide.where ) }</p>
                <ol className="fc-guide-steps">
                  { guide.steps.map( ( step, index ) => (
                    <li key={ index }>{ emphasise( step ) }</li>
                  ) ) }
                </ol>
                <p className="fc-guide-then">{ emphasise( guide.then ) }</p>
              </Card>
            ) ) }
          </section>
        );
      } ) }
    </div>
  );
}
