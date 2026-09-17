import { useEffect, useState } from 'react';
import { Receipt } from 'lucide-react';
import type { PackagesAnswer, PackageVersion, SupportPackage } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Tag, TextArea, TextInput } from '../kit';
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
  const [ selectedId, setSelectedId ] = useState< string | null >( null );
  const [ panel, setPanel ] = useState< 'add' | 'revise' | null >( null );
  const [ busy, setBusy ] = useState( false );

  const selected = packages.find( ( one ) => one.id === selectedId ) ?? null;

  /** A write's answer is the whole catalogue, so it is shown rather than re-read. */
  function landed( fresh: PackagesAnswer, said = '' ) {
    setPackages( fresh.packages );
    setPanel( null );
    setNotice( said );

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
    setNotice( '' );

    try {
      landed( await api< PackagesAnswer >( `/packages/${ target.id }`, { method: 'PATCH', body: { status: retiring ? 'retired' : 'active' } } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'That package could not be changed.' ) );
    } finally {
      setBusy( false );
    }
  }

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

  const versionColumns: Column< PackageVersion >[] = [
    { key: 'version', label: 'Version', mono: true, width: 80, render: ( v ) => `v${ v.version }` },
    { key: 'name', label: 'Name', wrap: true, render: ( v ) => v.name },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, render: ( v ) => hoursLabel( v.hours ) },
    { key: 'price', label: 'Price', mono: true, align: 'right', width: 120, render: ( v ) => priceLabel( v.price, v.currency ) },
    { key: 'months', label: 'Runs for', mono: true, align: 'right', width: 100, render: ( v ) => `${ v.validity_months } months` },
    { key: 'from', label: 'From', mono: true, width: 120, render: ( v ) => dateOf( v.created_at ) },
    { key: 'terms', label: 'Terms', wrap: true, clamp: 2, render: ( v ) => v.terms || '—' },
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

          <Panel
            title="The catalogue"
            right={
              <Button size="sm" data-testid="bwx-packages-add" disabled={ busy } onClick={ () => setPanel( 'add' ) }>
                Add a package
              </Button>
            }
          >
            <DataView< SupportPackage >
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
  const [ terms, setTerms ] = useState( from?.terms ?? '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = { name, hours: Number( hours ) || 0, price: Number( price ) || 0, currency, validity_months: Number( months ) || 0, terms };

    try {
      if ( revising ) {
        const answer = await api< PackagesAnswer >( `/packages/${ revising.id }/versions`, { method: 'POST', body } );

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
      <Field label="Price">
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
