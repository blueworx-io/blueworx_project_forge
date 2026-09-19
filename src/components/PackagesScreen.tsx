import { useEffect, useState } from 'react';
import { Receipt } from 'lucide-react';
import type { PackagesAnswer, PackageVersion, SupportPackage } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Select, Tag, TextArea, TextInput } from '../kit';
import type { Column } from '../kit';
import { failed, NOTHING_SAID, Notice, ok, Screen } from './States';
import type { Said } from './States';

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

/** "12h/month" or "12h/year" (2026-09-19). */
function perLabel( version: PackageVersion ): string {
  return `${ hoursLabel( version.hours ) }/${ version.hours_per ?? 'year' }`;
}

/** What an hour costs on these terms. */
function perHourLabel( version: PackageVersion ): string {
  return version.price_per_hour > 0 ? `${ version.currency } ${ version.price_per_hour.toFixed( 2 ) }` : '—';
}

function dateOf( seconds: number ): string {
  return new Date( seconds * 1000 ).toISOString().slice( 0, 10 );
}

export function PackagesScreen() {
  const [ packages, setPackages ] = useState< SupportPackage[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState< Said >( NOTHING_SAID );
  const [ selectedId, setSelectedId ] = useState< string | null >( null );
  const [ panel, setPanel ] = useState< 'add' | 'revise' | null >( null );
  const [ busy, setBusy ] = useState( false );

  const selected = packages.find( ( one ) => one.id === selectedId ) ?? null;

  /** A write's answer is the whole catalogue, so it is shown rather than re-read. */
  function landed( fresh: PackagesAnswer, said = '' ) {
    setPackages( fresh.packages );
    setPanel( null );
    setNotice( '' === said ? NOTHING_SAID : ok( said ) );

    if ( fresh.package ) {
      setSelectedId( fresh.package.id );
    }
  }

  async function setStatus( target: SupportPackage ) {
    const retiring = 'active' === target.status;
    const question = retiring
      ? `Retire ${ target.name }? Nobody new can be put on it; everybody already on it keeps it.`
      : `Put ${ target.name } back on the shelf?`;

    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      landed( await api< PackagesAnswer >( `/packages/${ target.id }`, { method: 'PATCH', body: { status: retiring ? 'retired' : 'active', record_version: target.record_version } } ) );
    } catch ( error ) {
      setNotice( failed( messageFor( error, 'That package could not be changed.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  /** Swaps a package with its neighbour and sends the whole order, which is what the route takes. */
  async function move( target: SupportPackage, by: -1 | 1 ) {
    const ids = packages.map( ( one ) => one.id );
    const at = ids.indexOf( target.id );
    const to = at + by;

    if ( at < 0 || to < 0 || to >= ids.length ) {
      return;
    }

    [ ids[ at ], ids[ to ] ] = [ ids[ to ], ids[ at ] ];

    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      landed( await api< PackagesAnswer >( '/packages/order', { method: 'PUT', body: { order: ids } } ) );
    } catch ( error ) {
      setNotice( failed( messageFor( error, 'The order could not be saved.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  async function load() {
    setNotice( NOTHING_SAID );

    try {
      const fresh = await api< PackagesAnswer >( '/packages' );

      setPackages( fresh.packages );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( failed( messageFor( error, 'The catalogue could not be read.' ) ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  useLiveReload( () => load() );

  const columns: Column< SupportPackage >[] = [
    {
      key: 'order',
      label: 'Order',
      width: 88,
      render: ( p ) => {
        const at = packages.findIndex( ( one ) => one.id === p.id );

        return (
          <span className="bwx-packages-order">
            <Button variant="ghost" size="sm" aria-label={ `Move ${ p.name } up` } data-testid="bwx-packages-up" disabled={ busy || 0 === at } onClick={ ( event ) => { event.stopPropagation(); void move( p, -1 ); } }>
              ↑
            </Button>
            <Button variant="ghost" size="sm" aria-label={ `Move ${ p.name } down` } data-testid="bwx-packages-down" disabled={ busy || at === packages.length - 1 } onClick={ ( event ) => { event.stopPropagation(); void move( p, 1 ); } }>
              ↓
            </Button>
          </span>
        );
      },
    },
    { key: 'name', label: 'Name', wrap: true, render: ( p ) => p.name },
    {
      key: 'status',
      label: 'Status',
      width: 120,
      render: ( p ) => ( 'retired' === p.status ? <Tag tone="neutral">Retired</Tag> : <Tag tone="ok">On the shelf</Tag> ),
    },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 110, render: ( p ) => ( p.current ? perLabel( p.current ) : '—' ) },
    { key: 'price', label: 'Price', mono: true, align: 'right', width: 120, render: ( p ) => ( p.current ? priceLabel( p.current.price, p.current.currency ) : '—' ) },
    { key: 'per_hour', label: 'Per hour', mono: true, align: 'right', width: 110, render: ( p ) => ( p.current ? perHourLabel( p.current ) : '—' ) },
    { key: 'months', label: 'Runs for', mono: true, align: 'right', width: 100, render: ( p ) => ( p.current ? `${ p.current.validity_months } months` : '—' ) },
    { key: 'version', label: 'Version', mono: true, align: 'right', width: 80, render: ( p ) => ( p.current ? `v${ p.current.version }` : '—' ) },
  ];

  const versionColumns: Column< PackageVersion >[] = [
    { key: 'version', label: 'Version', mono: true, width: 80, render: ( v ) => `v${ v.version }` },
    { key: 'name', label: 'Name', wrap: true, render: ( v ) => v.name },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 110, render: ( v ) => perLabel( v ) },
    { key: 'price', label: 'Price', mono: true, align: 'right', width: 120, render: ( v ) => priceLabel( v.price, v.currency ) },
    { key: 'per_hour', label: 'Per hour', mono: true, align: 'right', width: 110, render: ( v ) => perHourLabel( v ) },
    { key: 'months', label: 'Runs for', mono: true, align: 'right', width: 100, render: ( v ) => `${ v.validity_months } months` },
    { key: 'from', label: 'From', mono: true, width: 120, render: ( v ) => dateOf( v.created_at ) },
    { key: 'terms', label: 'Terms', wrap: true, clamp: 2, render: ( v ) => v.terms || '—' },
  ];

  return (
    <div className="bwx-packages" data-testid="bwx-packages">
      { 'loading' === state && <Screen state="loading" testId="bwx-packages-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-packages-state" detail="The catalogue is configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-packages-state" detail={ notice.text } /> }

      { 'ready' === state && (
        <>
          <Notice said={ notice } testId="bwx-packages-notice" />

          <Panel
            title="The catalogue"
            flush
            right={
              <Button size="sm" data-testid="bwx-packages-add" disabled={ busy } onClick={ () => setPanel( 'add' ) }>
                Add a package
              </Button>
            }
          >
            <DataView< SupportPackage >
              bare
              fixed
              columns={ columns }
              rows={ packages }
              sortable={ false }
              selectedId={ selectedId }
              onRowClick={ ( p ) => setSelectedId( p.id ) }
              empty={ <EmptyState icon={ Receipt } dense title="No packages yet" body="Add the first one. It becomes version 1, and every change after that is a new version." /> }
              footer={ `${ packages.length } in the catalogue, in the order clients see them` }
              testId="bwx-packages-list"
            />
            <span data-testid="bwx-packages-count" data-count={ packages.length } className="bwx-visually-hidden">
              { `${ packages.length } packages` }
            </span>
          </Panel>

          { selected && (
            <div data-testid="bwx-packages-selected" data-package={ selected.id }>
              <Panel
                title={ `Every version of ${ selected.name }` }
                flush
                right={
                  <div className="bwx-moves">
                    <Button size="sm" data-testid="bwx-packages-revise" disabled={ busy } onClick={ () => setPanel( 'revise' ) }>
                      Revise
                    </Button>
                    <Button size="sm" variant="ghost" data-testid="bwx-packages-status" disabled={ busy } onClick={ () => void setStatus( selected ) }>
                      { 'active' === selected.status ? 'Retire' : 'Put back on the shelf' }
                    </Button>
                  </div>
                }
              >
                <DataView< PackageVersion >
                  bare
                  fixed
                  columns={ versionColumns }
                  rows={ selected.versions }
                  sortable={ false }
                  footer={ `${ selected.versions.length } ${ 1 === selected.versions.length ? 'version' : 'versions' }. Each one was written once and never changed.` }
                  testId="bwx-packages-history"
                />
              </Panel>
            </div>
          ) }

          { 'add' === panel && <PackageForm onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'revise' === panel && selected && <PackageForm revising={ selected } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
        </>
      ) }
    </div>
  );
}

/** Adding a package, or revising one. Revising starts from the version in force, so a small change is a small edit. */
function PackageForm( {
  revising,
  onClose,
  onSaved,
}: {
  revising?: SupportPackage;
  onClose: () => void;
  onSaved: ( answer: PackagesAnswer, said?: string ) => void;
} ) {
  const from = revising?.current ?? null;
  const next = ( from?.version ?? 0 ) + 1;
  const [ name, setName ] = useState( from?.name ?? '' );
  const [ hours, setHours ] = useState( from ? String( from.hours ) : '' );
  const [ price, setPrice ] = useState( from ? String( from.price ) : '' );
  const [ currency, setCurrency ] = useState( from?.currency ?? 'GBP' );
  const [ months, setMonths ] = useState( from ? String( from.validity_months ) : '12' );
  const [ per, setPer ] = useState< 'year' | 'month' >( from?.hours_per ?? 'year' );
  const [ terms, setTerms ] = useState( from?.terms ?? '' );

  // What an hour would cost, worked out as it is typed.
  const total = 'month' === per ? ( Number( hours ) || 0 ) * ( Number( months ) || 0 ) : Number( hours ) || 0;
  const perHour = total > 0 && Number( price ) > 0 ? ( Number( price ) / total ).toFixed( 2 ) : '';
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = { name, hours: Number( hours ) || 0, price: Number( price ) || 0, currency, validity_months: Number( months ) || 0, hours_per: per, terms };

    try {
      if ( revising ) {
        const answer = await api< PackagesAnswer >( `/packages/${ revising.id }/versions`, { method: 'POST', body: { ...body, record_version: revising.record_version } } );

        onSaved( answer, answer.changed ? '' : 'Nothing changed, so no new version was written.' );
      } else {
        onSaved( await api< PackagesAnswer >( '/packages', { method: 'POST', body } ) );
      }
    } catch ( error ) {
      setNotice( messageFor( error, 'That package could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ revising ? `Revise ${ revising.name }` : 'Add a package' }
      width={ 560 }
      testId="bwx-packages-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-packages-form-save" disabled={ busy } onClick={ () => void save() }>
            { revising ? `Save as version ${ next }` : 'Add package' }
          </Button>
          <Button variant="ghost" data-testid="bwx-packages-form-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-packages-form-notice" role="status">
          { notice }
        </p>
      ) }

      { revising && (
        <p className="bwx-hint" data-testid="bwx-packages-form-hint">
          { `Saving writes version ${ next }. Version ${ from?.version ?? 0 } and every earlier one stay exactly as they are, and anybody on them keeps them.` }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-packages-form-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>
      <Field label="Hours" required help="How many hours the package holds. More than nought.">
        { ( id ) => <TextInput id={ id } type="number" min="0" step="0.25" inputMode="decimal" data-testid="bwx-packages-form-hours" value={ hours } onChange={ ( event ) => setHours( event.target.value ) } /> }
      </Field>
      <Field label="Hours are per" help="Per year is the whole term; per month is that many hours each month of it.">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-packages-form-per"
            value={ per }
            options={ [ { value: 'year', label: 'Year' }, { value: 'month', label: 'Month' } ] }
            onChange={ ( event ) => setPer( event.target.value as 'year' | 'month' ) }
          />
        ) }
      </Field>
      <Field label="Price" help={ '' !== perHour ? `${ currency } ${ perHour } an hour over the term.` : undefined }>
        { ( id ) => <TextInput id={ id } type="number" min="0" step="1" inputMode="numeric" data-testid="bwx-packages-form-price" value={ price } onChange={ ( event ) => setPrice( event.target.value ) } /> }
      </Field>
      <Field label="Currency" help="A three-letter code, such as GBP.">
        { ( id ) => <TextInput id={ id } maxLength={ 3 } data-testid="bwx-packages-form-currency" value={ currency } onChange={ ( event ) => setCurrency( event.target.value.toUpperCase() ) } /> }
      </Field>
      <Field label="Runs for (months)">
        { ( id ) => <TextInput id={ id } type="number" min="1" max="120" step="1" inputMode="numeric" data-testid="bwx-packages-form-months" value={ months } onChange={ ( event ) => setMonths( event.target.value ) } /> }
      </Field>
      <Field label="Terms">
        { ( id ) => <TextArea id={ id } rows={ 4 } maxLength={ 5000 } data-testid="bwx-packages-form-terms" value={ terms } onChange={ ( event ) => setTerms( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}
