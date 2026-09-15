import { useEffect, useState } from 'react';
import { CreditCard } from 'lucide-react';
import type { Stage, Subscription, SubscriptionConnection } from '../types';
import { api, forgeData, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, StageChip, Tag } from '../kit';
import type { Column } from '../kit';
import { ItemPanel } from './ItemPanel';
import { Screen } from './States';

/**
 * Every subscription across the connected SureCart stores, and the renewal
 * reminder each one has on the studio's board.
 *
 * Read-only by design: the stores are managed under Forge → Connections in
 * WordPress admin (ARCH-7), and the reminders are ordinary tasks the board
 * owns. This screen is for seeing what is coming and whether it was checked.
 */

interface Listing {
  denied: boolean;
  connections: SubscriptionConnection[];
  subscriptions: Subscription[];
}

function money( amount: number, currency: string ): string {
  const symbols: Record< string, string > = { GBP: '£', USD: '$', EUR: '€' };
  const figure = ( amount / 100 ).toFixed( 2 );

  return symbols[ currency ] ? `${ symbols[ currency ] }${ figure }` : `${ currency } ${ figure }`.trim();
}

function when( at: number ): string {
  return 0 === at ? 'never' : new Date( at * 1000 ).toLocaleString( [], { dateStyle: 'medium', timeStyle: 'short' } );
}

export function SubscriptionsScreen() {
  const [ listing, setListing ] = useState< Listing | null >( null );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ opened, setOpened ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  const canManage = forgeData()?.canManage ?? false;

  async function load() {
    try {
      const answer = await api< Listing >( '/subscriptions' );

      setListing( answer );
      setState( answer.denied ? 'denied' : 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Subscriptions could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    void api< { stages: Stage[] } >( '/stages' ).then( ( answer ) => setStages( answer.stages ) ).catch( () => undefined );
  }, [] );

  useLiveReload( load );

  async function refresh() {
    setBusy( true );
    setNotice( '' );

    try {
      await api( '/subscriptions/refresh', { method: 'POST' } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'The stores could not be asked.' ) );
    } finally {
      setBusy( false );
    }
  }

  const storeName = ( id: string ) => listing?.connections.find( ( one ) => one.id === id )?.name ?? '—';

  const columns: Column< Subscription >[] = [
    {
      key: 'customer',
      label: 'Customer',
      wrap: true,
      sortBy: ( r ) => r.customer_name,
      render: ( r ) => (
        <span className="bwx-subs-customer">
          <span>{ r.customer_name || 'Nobody named' }</span>
          { r.customer_email && <span className="bwx-mono bwx-subs-email">{ r.customer_email }</span> }
        </span>
      ),
    },
    { key: 'product', label: 'Product', wrap: true, sortBy: ( r ) => r.product_name, render: ( r ) => r.product_name || '—' },
    {
      key: 'amount',
      label: 'Amount',
      mono: true,
      align: 'right',
      width: 130,
      sortBy: ( r ) => r.amount,
      render: ( r ) => `${ money( r.amount, r.currency ) } ${ r.interval }`.trim(),
    },
    {
      key: 'status',
      label: 'Status',
      width: 100,
      sortBy: ( r ) => r.status,
      render: ( r ) => <Tag tone={ 'active' === r.status ? 'ok' : 'neutral' }>{ r.status }</Tag>,
    },
    { key: 'renews', label: 'Renews', mono: true, width: 110, sortBy: ( r ) => r.renews_on, render: ( r ) => r.renews_on || '—' },
    { key: 'store', label: 'Store', width: 140, sortBy: ( r ) => storeName( r.connection_id ), render: ( r ) => storeName( r.connection_id ) },
    {
      key: 'reminder',
      label: 'Reminder',
      width: 200,
      sortBy: ( r ) => r.reminder?.due_on ?? '',
      render: ( r ) =>
        r.reminder ? (
          <button type="button" className="bwx-row-open bwx-subs-reminder" data-testid="bwx-subs-reminder" onClick={ () => setOpened( r.reminder?.work_item_id ?? '' ) }>
            <StageChip stage={ r.reminder.stage } dense />
            <span className="bwx-mono">{ r.reminder.due_on }</span>
          </button>
        ) : (
          <span className="bwx-muted">none yet</span>
        ),
    },
  ];

  return (
    <>
      { 'loading' === state && <Screen state="loading" testId="bwx-subs-state" /> }
      { 'denied' === state && (
        <Screen state="denied" testId="bwx-subs-state" detail="Subscriptions are the studio's own. You are signed in, but not on the studio's site." />
      ) }
      { 'error' === state && <Screen state="error" testId="bwx-subs-state" detail={ notice } /> }

      { 'ready' === state && listing && (
        <div className="bwx-subs" data-testid="bwx-subscriptions">
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-subs-notice" role="status">
              { notice }
            </p>
          ) }

          <ul className="bwx-subs-stores" data-testid="bwx-subs-stores">
            { 0 === listing.connections.length && (
              <li className="bwx-muted">No SureCart store is connected yet. Add one under Forge → Connections in WordPress admin.</li>
            ) }
            { listing.connections.map( ( store ) => (
              <li key={ store.id } data-testid="bwx-subs-store" data-ok={ store.last_error ? 'false' : 'true' }>
                <strong>{ store.name }</strong>
                { store.last_error ? (
                  <span className="bwx-subs-store-error"> — { store.last_error }</span>
                ) : (
                  <span className="bwx-muted">
                    { ' ' }
                    — { store.last_count } active, refreshed { when( store.last_ok_at ) }
                  </span>
                ) }
              </li>
            ) ) }
          </ul>

          <DataView< Subscription >
            title="Subscriptions"
            titleRight={
              canManage ? (
                <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-subs-refresh" disabled={ busy } onClick={ () => void refresh() }>
                  { busy ? 'Asking…' : 'Refresh from SureCart' }
                </button>
              ) : undefined
            }
            columns={ columns }
            rows={ listing.subscriptions }
            sortable
            empty={ <EmptyState icon={ CreditCard } dense title="No subscriptions" body="Once a store is connected and refreshed, its active subscriptions appear here with their renewal dates." /> }
            footer={ `${ listing.subscriptions.length } subscriptions · on each renewal day a "Subscription Renewal" task lands on the studio's board for the store's staff` }
            testId="bwx-subs-table"
          />
        </div>
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </>
  );
}
