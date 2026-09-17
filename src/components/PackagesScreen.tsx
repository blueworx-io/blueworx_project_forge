import { useEffect, useState } from 'react';
import { Receipt } from 'lucide-react';
import type { PackagesAnswer, PackageVersion, SupportPackage } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, Panel, Tag } from '../kit';
import type { Column } from '../kit';
import { Screen } from './States';

/**
 * The support package catalogue (#145), in the app.
 *
 * The second configuration screen to leave WordPress admin. Reads and writes
 * `/packages` and nothing else; the admin page stays beside it until every
 * screen has moved.
 *
 * COMM-1 is what the screen is for: a package is never edited, it is revised,
 * and the revision is a new version with every earlier one still in the
 * list. The form says so in words before anybody presses Save.
 */

/** 12 → "12h", 7.5 → "7.5h". */
export function hoursLabel( value: number ): string {
  return `${ String( Number( value.toFixed( 2 ) ) ) }h`;
}

/** 1200, 'GBP' → "GBP 1,200". The code rather than a symbol: it is what the record holds. */
export function priceLabel( price: number, currency: string ): string {
  return `${ currency } ${ price.toLocaleString( 'en-GB' ) }`;
}

function dateOf( seconds: number ): string {
  return new Date( seconds * 1000 ).toISOString().slice( 0, 10 );
}

export function PackagesScreen() {
  const [ packages, setPackages ] = useState< SupportPackage[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );

  async function load() {
    setNotice( '' );

    try {
      const fresh = await api< PackagesAnswer >( '/packages' );

      setPackages( fresh.packages );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'The catalogue could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  useLiveReload( () => load() );

  const columns: Column< SupportPackage >[] = [
    { key: 'name', label: 'Name', wrap: true, render: ( p ) => p.name },
    {
      key: 'status',
      label: 'Status',
      width: 120,
      render: ( p ) => ( 'retired' === p.status ? <Tag tone="neutral">Retired</Tag> : <Tag tone="ok">On the shelf</Tag> ),
    },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, render: ( p ) => ( p.current ? hoursLabel( p.current.hours ) : '—' ) },
    { key: 'price', label: 'Price', mono: true, align: 'right', width: 120, render: ( p ) => ( p.current ? priceLabel( p.current.price, p.current.currency ) : '—' ) },
    { key: 'months', label: 'Runs for', mono: true, align: 'right', width: 100, render: ( p ) => ( p.current ? `${ p.current.validity_months } months` : '—' ) },
    { key: 'version', label: 'Version', mono: true, align: 'right', width: 80, render: ( p ) => ( p.current ? `v${ p.current.version }` : '—' ) },
  ];

  return (
    <div className="bwx-packages" data-testid="bwx-packages">
      { 'loading' === state && <Screen state="loading" testId="bwx-packages-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-packages-state" detail="The catalogue is configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-packages-state" detail={ notice } /> }

      { 'ready' === state && (
        <>
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-packages-notice" role="status">
              { notice }
            </p>
          ) }

          <Panel title="The catalogue">
            <DataView< SupportPackage >
              columns={ columns }
              rows={ packages }
              sortable={ false }
              empty={ <EmptyState icon={ Receipt } dense title="No packages yet" body="Add the first one. It becomes version 1, and every change after that is a new version." /> }
              footer={ `${ packages.length } in the catalogue, in the order clients see them` }
              testId="bwx-packages-list"
            />
            <span data-testid="bwx-packages-count" data-count={ packages.length } className="bwx-visually-hidden">
              { `${ packages.length } packages` }
            </span>
          </Panel>
        </>
      ) }
    </div>
  );
}

// Referenced by later tasks; kept here so the file compiles with the unused import rule.
export type { PackageVersion };
