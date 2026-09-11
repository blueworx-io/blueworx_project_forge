import { Clock, CloudOff, Package as PackageIcon } from 'lucide-react';
import { Button, Card, DataView, EmptyState, Eyebrow, Meter, Tag } from '../../kit';
import type { Column } from '../../kit';
import { api, day, hours } from '../api';
import type { Package, Purchase, SalesView, WorkspaceView } from '../api';
import { useView } from '../useView';

/*
 * Support & hours (#302): what the plan covers, what is left, what is on
 * offer, and what has been bought. Every figure is the studio's, read as
 * calculated — the balance here is the balance the studio sees, because
 * there is one ledger and both sides read it.
 *
 * Nothing here sells anything (COMM-2). Packages and top-ups are assigned by
 * the studio, so the offer is a conversation with the point of contact, and
 * the screen never looks like a checkout.
 */

function money( pence: number, currency: string ): string {
  const symbol = { GBP: '£', EUR: '€', USD: '$' }[ currency ] ?? `${ currency } `;
  return `${ symbol }${ ( pence / 100 ).toLocaleString( 'en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }`;
}

function runsFor( months: number ): string {
  if ( 12 === months ) return 'a year';
  if ( 1 === months ) return 'a month';
  return `${ months } months`;
}

function purchaseLabel( purchase: Purchase ): string {
  const base = 'top-up' === purchase.kind ? 'Extra hours' : 'Your package';
  return purchase.reason ? `${ base } · ${ purchase.reason }` : base;
}

function Plan( { sales }: { sales: SalesView } ) {
  const entitlement = Array.isArray( sales.entitlement ) ? null : sales.entitlement;
  const support = Array.isArray( sales.support ) ? null : sales.support;
  const granted = entitlement?.hours_granted ?? 0;
  const topUps = sales.purchases.filter( ( p ) => 'top-up' === p.kind ).reduce( ( sum, p ) => sum + ( p.hours ?? 0 ), 0 );
  const allocation = granted + topUps;
  const balance = sales.balance ?? 0;
  const used = Math.max( 0, allocation - balance );
  const active = entitlement?.may_use_hours ?? false;
  const noChargeableWork = support?.refused.includes( 'chargeable-work' ) ?? false;

  return (
    <Card testId="bwx-plan">
      <div className="fc-card-head">
        <h3>Your plan</h3>
        { entitlement && (
          <Tag tone={ active ? 'ok' : 'warn' } dot={ active } wrap>
            { entitlement.label }
          </Tag>
        ) }
        { entitlement?.starts_on && (
          <span className="fc-card-head-right fc-muted">
            Effective { day( entitlement.starts_on ) }
            { entitlement.term_ends_on ? ` · runs to ${ day( entitlement.term_ends_on ) }` : '' }
          </span>
        ) }
      </div>

      { entitlement && allocation > 0 ? (
        <>
          <Meter label={ `${ hours( granted ) } package${ topUps > 0 ? ` + ${ hours( topUps ) } top-up` : '' }` } allocation={ allocation } used={ used } note={ `${ hours( balance ) } remaining` } warn={ balance <= 0 } />
          <dl className="fc-breakdown" data-testid="bwx-breakdown">
            <div>
              <dt>Package hours</dt>
              <dd className="fk-mono">{ hours( granted ) }</dd>
            </div>
            <div>
              <dt>Top-ups</dt>
              <dd className="fk-mono">{ hours( topUps ) }</dd>
            </div>
            <div>
              <dt>Used</dt>
              <dd className="fk-mono">{ hours( used ) }</dd>
            </div>
            <div>
              <dt>Remaining</dt>
              <dd className="fk-mono" data-testid="bwx-balance">
                { hours( balance ) }
              </dd>
            </div>
          </dl>
          <p className="fc-muted">
            The split between work and meetings is not sent by the studio yet, so it is not drawn. What is shown is the
            same total the studio sees.
          </p>
        </>
      ) : (
        <EmptyState
          icon={ Clock }
          dense
          title={ entitlement ? 'No hours on your plan' : 'Not read yet' }
          body={ entitlement ? 'Nothing has been assigned to your site yet. The packages below are what is on offer.' : 'Your support position has not been read from the studio yet.' }
        />
      ) }

      { noChargeableWork && (
        <p className="fc-notice" data-testid="bwx-support-refused">
          New chargeable work cannot be scheduled until a support package is in place. You can still report anything
          that is broken, ask for something, and talk to your contact about a package.
        </p>
      ) }
    </Card>
  );
}

function Contact( { workspace }: { workspace: WorkspaceView | null } ) {
  const contact = workspace && ! Array.isArray( workspace.contact ) ? workspace.contact : null;

  return (
    <Card tone="sunken" testId="bwx-sales-contact">
      <h3 className="fc-h3">Changing your plan</h3>
      <p className="fc-body">
        Packages and top-ups are set up by the studio, so there is nothing to buy here. Tell your point of contact what
        you would like and they will put it on your account — the hours appear on this page as soon as they do.
      </p>
      { contact?.display_name && (
        <div className="fc-facts">
          <div>
            <Eyebrow>Point of contact</Eyebrow>
            <div className="fc-contact-name">{ contact.display_name }</div>
            { contact.role && <div className="fc-contact-role">{ contact.role }</div> }
          </div>
        </div>
      ) }
      { contact?.email && (
        <a className="fk-btn" data-variant="secondary" data-size="sm" href={ `mailto:${ contact.email }` }>
          Email { contact.display_name || 'your contact' }
        </a>
      ) }
    </Card>
  );
}

function Packages( { packages }: { packages: Package[] } ) {
  if ( 0 === packages.length ) {
    return (
      <Card>
        <EmptyState icon={ PackageIcon } dense title="Nothing on offer right now" body="The studio has not published any packages. Ask your point of contact what is available." />
      </Card>
    );
  }
  return (
    <div className="fc-packages" data-testid="bwx-packages">
      { packages.map( ( p ) => (
        <Card key={ p.name } className="fc-package" testId="bwx-package">
          <strong className="fc-package-name">{ p.name }</strong>
          <span className="fc-package-hours fk-mono">{ hours( p.hours ) }</span>
          <span className="fc-muted">
            { money( p.price, p.currency ) } · runs for { runsFor( p.validity_months ) }
          </span>
        </Card>
      ) ) }
    </div>
  );
}

interface Row extends Record< string, unknown > {
  id: string;
  purchase: Purchase;
}

function History( { purchases }: { purchases: Purchase[] } ) {
  const rows: Row[] = purchases.map( ( purchase, i ) => ( { id: String( i ), purchase } ) );
  const columns: Column< Row >[] = [
    { key: 'on', label: 'When', mono: true, width: 130, sortBy: ( r ) => r.purchase.on ?? '', render: ( r ) => day( r.purchase.on ?? '' ) },
    { key: 'what', label: 'What', wrap: true, sortBy: ( r ) => purchaseLabel( r.purchase ), render: ( r ) => purchaseLabel( r.purchase ) },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 100, sortBy: ( r ) => r.purchase.hours ?? 0, render: ( r ) => `+${ hours( r.purchase.hours ) }` },
    {
      key: 'expires',
      label: 'Runs out',
      mono: true,
      width: 150,
      sortBy: ( r ) => r.purchase.expires_at ?? 0,
      render: ( r ) => ( r.purchase.expires_at ? new Date( r.purchase.expires_at * 1000 ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } ) : 'With your package' ),
    },
  ];

  return (
    <Card pad={ 0 } testId="bwx-purchases">
      <DataView
        title="What you have bought"
        columns={ columns }
        rows={ rows }
        defaultSort={ { key: 'on', dir: 'desc' } }
        empty={ <EmptyState icon={ Clock } dense title="Nothing yet" body="Packages and top-ups assigned to your site are listed here, with the hours each added." /> }
        footer="The same entries the studio team sees, from the one record."
      />
    </Card>
  );
}

export function Sales() {
  const sales = useView( api.sales );
  const workspace = useView( api.workspace );

  return (
    <div className="fc-stack" data-testid="bwx-sales">
      { sales.loading ? (
        <Card>
          <p className="fc-muted" role="status" aria-busy="true">
            Reading your hours…
          </p>
        </Card>
      ) : sales.view?.ok ? (
        <>
          <div className="fc-two-up fc-two-up-sales">
            <Plan sales={ sales.view } />
            <Contact workspace={ workspace.view } />
          </div>

          <section>
            <h3 className="fc-h3">Support packages</h3>
            <Packages packages={ sales.view.packages } />
          </section>

          <History purchases={ sales.view.purchases } />
        </>
      ) : (
        <Card>
          <EmptyState
            icon={ CloudOff }
            hue="amber"
            dense
            title="Your hours could not be read"
            body="The studio did not answer. What you last saw is still on your WordPress dashboard."
            action={
              <Button variant="secondary" size="sm" onClick={ () => sales.reload( true ) }>
                Try again
              </Button>
            }
          />
        </Card>
      ) }
    </div>
  );
}
