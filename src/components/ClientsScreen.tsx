import { useEffect, useState } from 'react';
import { Building2, Globe } from 'lucide-react';
import type { ClientContact, ClientRecord, ClientRow, ClientSiteRecord, IssuedKey, Person, SiteIntegration } from '../types';
import { api, ApiError, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Select, Tag, TextInput } from '../kit';
import type { Column } from '../kit';
import { failed, NOTHING_SAID, Notice, ok, Screen } from './States';
import type { Said } from './States';

/**
 * Who we work for, and their sites (PR 4 of spec 2026-09-16), in the app.
 *
 * The fourth configuration screen to leave WordPress admin. The clients are a
 * list; picking one opens its panel, with the sites beneath it, because a
 * site means nothing without the client above it and the admin page's one
 * long card per client made a studio with forty clients scroll for a minute
 * to find one site.
 *
 * Three facts are drawn per site and none is worked out here: whether it is
 * connected (#89), where its onboarding is (#160), and its status. A key is
 * issued from a panel that shows it once, from the one answer that carries
 * it, and forgets it when the panel closes — there is nowhere to read it
 * back from, by design.
 */

/** A client nobody has been named for. What the list answers for a brand-new client. */
const NOBODY: ClientContact = { contact: null, needs_reassignment: true, fallback: 'studio' };

/** A write's answer folded over what the list already knows about that client. */
function fold( fresh: ClientRow, known: ClientRecord | null ): ClientRecord {
  return { ...fresh, is_studio: known?.is_studio ?? false, contact: known?.contact ?? NOBODY };
}

/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

/** The studio first, then everyone else by name. */
function byName( a: ClientRecord, b: ClientRecord ): number {
  if ( a.is_studio !== b.is_studio ) {
    return a.is_studio ? -1 : 1;
  }

  return a.display_name.localeCompare( b.display_name );
}

function dateOf( seconds: number ): string {
  return new Date( seconds * 1000 ).toISOString().slice( 0, 10 );
}

/** Who looks after a client, in words: the person, or the studio standing in. */
export function contactLabel( contact: ClientContact ): string {
  if ( ! contact.contact ) {
    return 'Nobody';
  }

  return contact.needs_reassignment ? `${ contact.contact.display_name } (has left)` : contact.contact.display_name;
}

/**
 * How a site's connection reads. The four states the studio acts on are named
 * here; the two it watches (broken, idle) keep the server's own words.
 */
export function connectionLabel( integration: SiteIntegration | null ): string {
  switch ( integration?.health ) {
    case 'connected':
      return 'Connected';
    case 'never_connected':
      return 'Key issued, not yet connected';
    case 'revoked':
      return 'Cut off';
    case 'broken':
    case 'idle':
      return integration.health_label;
    default:
      return 'Not connected';
  }
}

function connectionTone( integration: SiteIntegration | null ): 'ok' | 'warn' | 'danger' | 'neutral' {
  switch ( integration?.health ) {
    case 'connected':
      return 'ok';
    case 'idle':
    case 'never_connected':
      return 'warn';
    case 'broken':
    case 'revoked':
      return 'danger';
    default:
      return 'neutral';
  }
}

type Opened =
  | { kind: 'add' }
  | { kind: 'edit' }
  | { kind: 'contact' }
  | { kind: 'add-site' }
  | { kind: 'edit-site'; site: string }
  | { kind: 'key'; site: string };

export function ClientsScreen() {
  const [ clients, setClients ] = useState< ClientRecord[] >( [] );
  // The sites of every client picked so far, by client id: read once each.
  const [ sites, setSites ] = useState< Record< string, ClientSiteRecord[] > >( {} );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState< Said >( NOTHING_SAID );
  const [ everyone, setEveryone ] = useState( false );
  const [ selectedId, setSelectedId ] = useState< string | null >( null );
  const [ opened, setOpened ] = useState< Opened | null >( null );
  const [ busy, setBusy ] = useState( false );

  const selected = clients.find( ( one ) => one.id === selectedId ) ?? null;
  const selectedSites = selected ? sites[ selected.id ] ?? null : null;
  const shown = everyone ? clients : clients.filter( ( one ) => 'active' === one.status );
  const shownSites = ( selectedSites ?? [] ).filter( ( one ) => everyone || 'active' === one.status );
  const targetSite = opened && 'site' in opened ? selectedSites?.find( ( one ) => one.id === opened.site ) ?? null : null;

  /** A client write's answer is that client; their row is replaced rather than the list re-read. */
  function landed( fresh: ClientRow, said = '' ) {
    setClients( ( current ) => [ ...current.filter( ( one ) => one.id !== fresh.id ), fold( fresh, current.find( ( one ) => one.id === fresh.id ) ?? null ) ].sort( byName ) );
    setOpened( null );
    setNotice( '' === said ? NOTHING_SAID : ok( said ) );
  }

  /** A site write answers the site alone; the row wants its connection and onboarding too, so the client's sites are read again. */
  async function readSites( clientId: string ) {
    const answer = await api< { ok: true; sites: ClientSiteRecord[] } >( `/clients/${ clientId }/sites?status=all` );

    setSites( ( current ) => ( { ...current, [ clientId ]: answer.sites } ) );
  }

  async function sitesLanded( clientId: string, said = '' ) {
    await readSites( clientId );
    setOpened( null );
    setNotice( '' === said ? NOTHING_SAID : ok( said ) );
  }

  /** One site's connection changed in the key panel; its row takes the new record. */
  function integrationLanded( site: ClientSiteRecord, integration: SiteIntegration ) {
    setSites( ( current ) => ( {
      ...current,
      [ site.client_id ]: ( current[ site.client_id ] ?? [] ).map( ( one ) => ( one.id === site.id ? { ...one, integration } : one ) ),
    } ) );
  }

  /** A confirmed action from a row or a panel. */
  async function act( question: string, write: () => Promise< void >, fallback: string ) {
    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      await write();
    } catch ( error ) {
      setNotice( failed( messageFor( error, fallback ) ) );
    } finally {
      setBusy( false );
    }
  }

  function deactivateClient( target: ClientRecord ) {
    void act(
      `Deactivate ${ target.display_name } and every site under it?`,
      async () => {
        const answer = await api< { ok: true; client: ClientRow } >( `/clients/${ target.id }`, { method: 'PATCH', body: { status: 'inactive', record_version: target.record_version } } );

        // The cascade closed its sites too, so what was read of them is stale.
        setSites( ( current ) => Object.fromEntries( Object.entries( current ).filter( ( [ id ] ) => id !== target.id ) ) );
        landed( answer.client, `${ target.display_name } has been deactivated, and every site under it.` );
      },
      'That client could not be deactivated.'
    );
  }

  function deactivateSite( target: ClientSiteRecord ) {
    void act(
      `Deactivate ${ target.name }?`,
      async () => {
        await api( `/client-sites/${ target.id }`, { method: 'PATCH', body: { status: 'inactive', record_version: target.record_version } } );
        await sitesLanded( target.client_id, `${ target.name } has been deactivated.` );
      },
      'That site could not be deactivated.'
    );
  }

  function startOnboarding( target: ClientSiteRecord ) {
    const version = target.onboarding ? ` (v${ target.onboarding.template_version })` : '';

    void act(
      `Start onboarding for ${ target.name }${ version }? The checklist is fixed at this version and cannot be changed afterwards.`,
      async () => {
        await api( `/client-sites/${ target.id }/onboarding`, { method: 'POST' } );
        await sitesLanded( target.client_id, 'Onboarding started. Their checklist is fixed at this version.' );
      },
      'Onboarding could not be started.'
    );
  }

  async function load() {
    setNotice( NOTHING_SAID );

    try {
      const fresh = await api< { ok: true; clients: ClientRecord[] } >( '/clients?status=all' );

      setClients( [ ...fresh.clients ].sort( byName ) );
      setState( 'ready' );

      if ( null !== selectedId ) {
        await readSites( selectedId );
      }
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( failed( messageFor( error, 'The clients could not be read.' ) ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  // A client's sites are read the first time it is picked, and kept.
  useEffect( () => {
    if ( null === selectedId || selectedId in sites ) {
      return;
    }

    // eslint-disable-next-line react-hooks/set-state-in-effect
    readSites( selectedId ).catch( ( error: unknown ) => setNotice( failed( messageFor( error, 'The sites could not be read.' ) ) ) );
  }, [ selectedId, sites ] );

  const columns: Column< ClientRecord >[] = [
    {
      key: 'name',
      label: 'Name',
      wrap: true,
      render: ( c ) => (
        <>
          { c.display_name }
          { c.is_studio && (
            <>
              { ' ' }
              <Tag tone="brand">Studio</Tag>
            </>
          ) }
        </>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      width: 120,
      render: ( c ) => ( 'active' === c.status ? <Tag tone="ok">Active</Tag> : <Tag tone="neutral">Deactivated</Tag> ),
    },
    { key: 'contact', label: 'Contact', wrap: true, render: ( c ) => contactLabel( c.contact ) },
    { key: 'timezone', label: 'Timezone', mono: true, width: 160, render: ( c ) => c.timezone },
  ];

  const siteColumns: Column< ClientSiteRecord >[] = [
    { key: 'name', label: 'Name', wrap: true, render: ( s ) => s.name },
    { key: 'url', label: 'Address', mono: true, wrap: true, render: ( s ) => s.url || '—' },
    {
      key: 'status',
      label: 'Status',
      width: 110,
      render: ( s ) => ( 'active' === s.status ? <Tag tone="ok">Active</Tag> : <Tag tone="neutral">Deactivated</Tag> ),
    },
    {
      key: 'onboarding',
      label: 'Onboarding',
      wrap: true,
      render: ( s ) => {
        if ( ! s.onboarding ) {
          return <Tag tone="neutral">No checklist yet</Tag>;
        }

        if ( ! s.onboarding.started ) {
          return <Tag tone="neutral">Not started</Tag>;
        }

        return (
          <>
            { s.onboarding.ready ? <Tag tone="ok">Ready</Tag> : <Tag tone="info">In progress</Tag> }
            { ' ' }
            <span className="fk-mono">
              { `v${ s.onboarding.template_version } · ${ Math.round( s.onboarding.completion ) }% done` }
              { s.onboarding.blocking > 0 && ` · ${ s.onboarding.blocking } blocking` }
            </span>
          </>
        );
      },
    },
    {
      key: 'connection',
      label: 'Connection',
      wrap: true,
      render: ( s ) => <Tag tone={ connectionTone( s.integration ) }>{ connectionLabel( s.integration ) }</Tag>,
    },
    {
      key: 'actions',
      label: '',
      width: 330,
      render: ( s ) => (
        <span className="bwx-moves">
          <Button size="sm" variant="ghost" data-testid="bwx-clients-site-edit" aria-label={ `Edit ${ s.name }` } disabled={ busy } onClick={ () => setOpened( { kind: 'edit-site', site: s.id } ) }>
            Edit
          </Button>
          { 'active' === s.status && (
            <>
              <Button size="sm" variant="ghost" data-testid="bwx-clients-site-key" aria-label={ `Connection key for ${ s.name }` } disabled={ busy } onClick={ () => setOpened( { kind: 'key', site: s.id } ) }>
                Key
              </Button>
              <Button
                size="sm"
                variant="ghost"
                data-testid="bwx-clients-site-onboard"
                aria-label={ `Start onboarding for ${ s.name }` }
                disabled={ busy || true === s.onboarding?.started }
                onClick={ () => startOnboarding( s ) }
              >
                Start onboarding
              </Button>
              <Button size="sm" variant="ghost" data-testid="bwx-clients-site-deactivate" aria-label={ `Deactivate ${ s.name }` } disabled={ busy } onClick={ () => deactivateSite( s ) }>
                Deactivate
              </Button>
            </>
          ) }
        </span>
      ),
    },
  ];

  return (
    <div className="bwx-clients" data-testid="bwx-clients">
      { 'loading' === state && <Screen state="loading" testId="bwx-clients-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-clients-state" detail="Clients are configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-clients-state" detail={ notice.text } /> }

      { 'ready' === state && (
        <>
          <Notice said={ notice } testId="bwx-clients-notice" />

          <Panel
            title="Who we work for"
            flush
            right={
              <div className="bwx-moves">
                <Button size="sm" variant="ghost" data-testid="bwx-clients-show-all" aria-pressed={ everyone } onClick={ () => setEveryone( ( on ) => ! on ) }>
                  { everyone ? 'Show active only' : 'Show everyone, including deactivated' }
                </Button>
                <Button size="sm" data-testid="bwx-clients-add" disabled={ busy } onClick={ () => setOpened( { kind: 'add' } ) }>
                  Add a client
                </Button>
              </div>
            }
          >
            <DataView< ClientRecord >
              bare
              columns={ columns }
              rows={ shown }
              sortable={ false }
              fixed
              selectedId={ selectedId }
              onRowClick={ ( c ) => setSelectedId( c.id ) }
              empty={ <EmptyState icon={ Building2 } dense title="No clients yet" body="Add the first one, and their sites go under them." /> }
              footer={ everyone ? `${ shown.length } clients, including deactivated` : `${ shown.length } active clients` }
              testId="bwx-clients-list"
            />
            <span data-testid="bwx-clients-count" data-count={ shown.length } className="bwx-visually-hidden">
              { everyone ? `${ shown.length } clients, including deactivated` : `${ shown.length } active clients` }
            </span>
          </Panel>

          { selected && (
            <div data-testid="bwx-clients-selected" data-client={ selected.id }>
              <Panel
                title={
                  <>
                    { selected.display_name }
                    { selected.is_studio && (
                      <>
                        { ' ' }
                        <Tag tone="brand">Studio</Tag>
                      </>
                    ) }
                  </>
                }
                flush
                right={
                  <div className="bwx-moves">
                    <Button size="sm" variant="ghost" data-testid="bwx-clients-edit" disabled={ busy } onClick={ () => setOpened( { kind: 'edit' } ) }>
                      Edit
                    </Button>
                    { ! selected.is_studio && (
                      <Button size="sm" variant="ghost" data-testid="bwx-clients-contact" disabled={ busy } onClick={ () => setOpened( { kind: 'contact' } ) }>
                        Set contact
                      </Button>
                    ) }
                    { 'active' === selected.status && (
                      <Button size="sm" variant="ghost" data-testid="bwx-clients-add-site" disabled={ busy } onClick={ () => setOpened( { kind: 'add-site' } ) }>
                        Add a site
                      </Button>
                    ) }
                    { 'active' === selected.status && ! selected.is_studio && (
                      <Button size="sm" variant="ghost" data-testid="bwx-clients-deactivate" disabled={ busy } onClick={ () => deactivateClient( selected ) }>
                        Deactivate
                      </Button>
                    ) }
                  </div>
                }
              >
                <p className="bwx-hint bwx-panel-lead" data-testid="bwx-clients-selected-detail">
                  { selected.is_studio ? (
                    'Your own work goes under this client. It appears in the site picker like any other, and the board opens on it.'
                  ) : (
                    <>
                      <span data-testid="bwx-clients-selected-contact">{ `Contact: ${ contactLabel( selected.contact ) }` }</span>
                      { selected.contact.needs_reassignment && ' — needs reassigning; until then the client\'s contact is the studio.' }
                      { ' · ' }
                      { selected.timezone }
                      { selected.email_domains.length > 0 && ` · ${ selected.email_domains.join( ', ' ) }` }
                    </>
                  ) }
                </p>
                <DataView< ClientSiteRecord >
                  bare
                  columns={ siteColumns }
                  rows={ shownSites }
                  sortable={ false }
                  fixed
                  empty={
                    null === selectedSites ? (
                      'Reading the sites…'
                    ) : (
                      <EmptyState icon={ Globe } dense title="No sites yet" body={ everyone ? 'Add one and it appears here.' : 'No active sites. Add one, or show everyone to see any that were deactivated.' } />
                    )
                  }
                  footer={ null === selectedSites ? undefined : `${ shownSites.length } ${ 1 === shownSites.length ? 'site' : 'sites' }` }
                  testId="bwx-clients-sites"
                />
              </Panel>
            </div>
          ) }

          { opened && 'add' === opened.kind && <ClientForm onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'edit' === opened.kind && selected && <ClientForm editing={ selected } onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'contact' === opened.kind && selected && (
            <ContactForm
              client={ selected }
              onClose={ () => setOpened( null ) }
              onSaved={ ( contact ) => {
                setClients( ( current ) => current.map( ( one ) => ( one.id === selected.id ? { ...one, contact } : one ) ) );
                setOpened( null );
              } }
            />
          ) }
          { opened && 'add-site' === opened.kind && selected && <SiteForm client={ selected } onClose={ () => setOpened( null ) } onSaved={ () => sitesLanded( selected.id ) } /> }
          { opened && 'edit-site' === opened.kind && selected && targetSite && (
            <SiteForm client={ selected } editing={ targetSite } onClose={ () => setOpened( null ) } onSaved={ () => sitesLanded( selected.id ) } />
          ) }
          { opened && 'key' === opened.kind && targetSite && (
            <KeyPanel site={ targetSite } onClose={ () => setOpened( null ) } onChanged={ ( integration ) => integrationLanded( targetSite, integration ) } />
          ) }
        </>
      ) }
    </div>
  );
}

/**
 * Adding a client, or editing one. The studio's own client is renamed and
 * nothing more: its timezone and domains are ours, and it cannot be
 * deactivated from here or anywhere.
 */
function ClientForm( {
  editing,
  onClose,
  onSaved,
}: {
  editing?: ClientRecord;
  onClose: () => void;
  onSaved: ( answer: ClientRow, said?: string ) => void;
} ) {
  const studio = true === editing?.is_studio;
  const [ name, setName ] = useState( editing?.display_name ?? '' );
  const [ legal, setLegal ] = useState( editing?.legal_name ?? '' );
  const [ timezone, setTimezone ] = useState( editing?.timezone ?? 'Europe/London' );
  const [ domains, setDomains ] = useState( editing?.email_domains.join( ', ' ) ?? '' );
  const [ status, setStatus ] = useState( editing?.status ?? 'active' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      if ( editing && studio ) {
        const answer = await api< { ok: true; client: ClientRow } >( '/studio', { method: 'PUT', body: { display_name: name, record_version: editing.record_version } } );

        onSaved( answer.client );
      } else if ( editing ) {
        const answer = await api< { ok: true; client: ClientRow } >( `/clients/${ editing.id }`, {
          method: 'PATCH',
          body: { display_name: name, legal_name: legal, timezone, email_domains: domains, status, record_version: editing.record_version },
        } );

        onSaved( answer.client, 'inactive' === status && 'active' === editing.status ? `${ name } has been deactivated, and every site under it.` : '' );
      } else {
        const answer = await api< { ok: true; client: ClientRow } >( '/clients', { method: 'POST', body: { display_name: name, legal_name: legal, timezone, email_domains: domains } } );

        onSaved( answer.client );
      }
    } catch ( error ) {
      setNotice( refusal( error, 'That client could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ editing ? `Edit ${ editing.display_name }` : 'Add a client' }
      description={ studio ? 'The studio\'s own client. Your own work goes under it.' : undefined }
      width={ 520 }
      testId="bwx-clients-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-clients-form-save" disabled={ busy } onClick={ () => void save() }>
            { editing ? 'Save' : 'Add client' }
          </Button>
          <Button variant="ghost" data-testid="bwx-clients-form-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-clients-form-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-clients-form-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>

      { ! studio && (
        <>
          <Field label="Legal name" help="If it differs from the name we know them by.">
            { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-clients-form-legal" value={ legal } onChange={ ( event ) => setLegal( event.target.value ) } /> }
          </Field>
          <Field label="Timezone" required help="As the tz database names it, such as Europe/London.">
            { ( id ) => <TextInput id={ id } maxLength={ 64 } data-testid="bwx-clients-form-timezone" value={ timezone } onChange={ ( event ) => setTimezone( event.target.value ) } /> }
          </Field>
          <Field label="Permitted email domains" help="Each one a domain on its own, separated by commas, such as acme.co.uk, acme.com.">
            { ( id ) => <TextInput id={ id } maxLength={ 1000 } placeholder="acme.co.uk, acme.com" data-testid="bwx-clients-form-domains" value={ domains } onChange={ ( event ) => setDomains( event.target.value ) } /> }
          </Field>
          { editing && (
            <Field label="Status" help="Deactivating closes every site under them too.">
              { ( id ) => (
                <Select
                  id={ id }
                  data-testid="bwx-clients-form-status"
                  value={ status }
                  onChange={ ( event ) => setStatus( event.target.value ) }
                  options={ [
                    { value: 'active', label: 'Active' },
                    { value: 'inactive', label: 'Deactivated' },
                  ] }
                />
              ) }
            </Field>
          ) }
        </>
      ) }
    </Modal>
  );
}

/** Who on our side looks after this client: one of the active people, or nobody. */
function ContactForm( { client, onClose, onSaved }: { client: ClientRecord; onClose: () => void; onSaved: ( contact: ClientContact ) => void } ) {
  const [ people, setPeople ] = useState< Person[] | null >( null );
  const [ picked, setPicked ] = useState( client.contact.needs_reassignment ? '' : client.contact.contact?.id ?? '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  useEffect( () => {
    api< { ok: true; users: Person[] } >( '/users' )
      .then( ( answer ) => setPeople( answer.users.filter( ( one ) => 'active' === one.status ) ) )
      .catch( ( error: unknown ) => {
        setPeople( [] );
        setNotice( messageFor( error, 'The people could not be read.' ) );
      } );
  }, [] );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      const answer = await api< { ok: true; contact: ClientContact } >( `/clients/${ client.id }/contact`, { method: 'PUT', body: { user_id: picked } } );

      onSaved( answer.contact );
    } catch ( error ) {
      setNotice( refusal( error, 'That contact could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ `Our contact for ${ client.display_name }` }
      description="Who looks after them on our side. Until somebody is named, the client's contact is the studio."
      width={ 480 }
      testId="bwx-clients-contact-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-clients-contact-save" disabled={ busy || null === people } onClick={ () => void save() }>
            Set contact
          </Button>
          <Button variant="ghost" data-testid="bwx-clients-contact-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-clients-contact-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Point of contact">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-clients-contact-pick"
            value={ picked }
            disabled={ null === people }
            onChange={ ( event ) => setPicked( event.target.value ) }
            options={ [
              { value: '', label: null === people ? 'Reading the people…' : 'Nobody' },
              ...( people ?? [] ).map( ( one ) => ( { value: one.id, label: one.display_name } ) ),
            ] }
          />
        ) }
      </Field>
    </Modal>
  );
}

/** Adding a site under a client, or editing one. Saved against the version it was read at. */
function SiteForm( {
  client,
  editing,
  onClose,
  onSaved,
}: {
  client: ClientRecord;
  editing?: ClientSiteRecord;
  onClose: () => void;
  onSaved: () => Promise< void >;
} ) {
  const [ name, setName ] = useState( editing?.name ?? '' );
  const [ url, setUrl ] = useState( editing?.url ?? '' );
  const [ status, setStatus ] = useState( editing?.status ?? 'active' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      if ( editing ) {
        await api( `/client-sites/${ editing.id }`, { method: 'PATCH', body: { name, url, status, record_version: editing.record_version } } );
      } else {
        await api( `/clients/${ client.id }/sites`, { method: 'POST', body: { name, url } } );
      }

      await onSaved();
    } catch ( error ) {
      setNotice( refusal( error, 'That site could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ editing ? `Edit ${ editing.name }` : `Add a site for ${ client.display_name }` }
      width={ 480 }
      testId="bwx-clients-site-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-clients-site-save" disabled={ busy } onClick={ () => void save() }>
            { editing ? 'Save' : 'Add site' }
          </Button>
          <Button variant="ghost" data-testid="bwx-clients-site-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-clients-site-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-clients-site-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>
      <Field label="Address" help="The site's web address, such as https://acme.co.uk.">
        { ( id ) => <TextInput id={ id } type="url" maxLength={ 2000 } placeholder="https://" data-testid="bwx-clients-site-url" value={ url } onChange={ ( event ) => setUrl( event.target.value ) } /> }
      </Field>
      { editing && (
        <Field label="Status">
          { ( id ) => (
            <Select
              id={ id }
              data-testid="bwx-clients-site-status"
              value={ status }
              onChange={ ( event ) => setStatus( event.target.value ) }
              options={ [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Deactivated' },
              ] }
            />
          ) }
        </Field>
      ) }
    </Modal>
  );
}

/** A value shown once, with a button that puts it on the clipboard and says so. */
function CopyField( { label, value, testId, copyTestId }: { label: string; value: string; testId: string; copyTestId: string } ) {
  const [ copied, setCopied ] = useState( false );

  async function copy() {
    try {
      await navigator.clipboard.writeText( value );
      setCopied( true );
    } catch {
      setCopied( false );
    }
  }

  return (
    <Field label={ label }>
      { ( id ) => (
        <span className="bwx-moves">
          <TextInput id={ id } readOnly data-testid={ testId } value={ value } onFocus={ ( event ) => event.target.select() } />
          <Button size="sm" variant="secondary" data-testid={ copyTestId } aria-label={ `Copy the ${ label.toLowerCase() }` } onClick={ () => void copy() }>
            { copied ? 'Copied' : 'Copy' }
          </Button>
        </span>
      ) }
    </Field>
  );
}

/**
 * A site's connection key (#89). Issued here, shown here once, and gone
 * when the panel closes: the key lives in this component's state and
 * nowhere else in the app, so there is nothing to read it back from.
 */
function KeyPanel( { site, onClose, onChanged }: { site: ClientSiteRecord; onClose: () => void; onChanged: ( integration: SiteIntegration ) => void } ) {
  const [ integration, setIntegration ] = useState< SiteIntegration | null >( site.integration );
  const [ issued, setIssued ] = useState< IssuedKey | null >( null );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const live = 'active' === integration?.key_state;

  async function issue() {
    const question = live ? 'Issue a new key? The site stops working until the new one is installed on it.' : `Issue a key for ${ site.name }?`;

    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      const answer = await api< IssuedKey >( `/client-sites/${ site.id }/integration/key`, { method: 'POST' } );

      setIssued( answer );
      setIntegration( answer.integration );
      onChanged( answer.integration );
    } catch ( error ) {
      setNotice( messageFor( error, 'That key could not be issued.' ) );
    } finally {
      setBusy( false );
    }
  }

  async function revoke() {
    if ( ! window.confirm( 'Cut this site off? Its key stops working immediately.' ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      const answer = await api< { ok: true; integration: SiteIntegration } >( `/client-sites/${ site.id }/integration/key`, { method: 'DELETE' } );

      setIssued( null );
      setIntegration( answer.integration );
      onChanged( answer.integration );
    } catch ( error ) {
      setNotice( messageFor( error, 'That key could not be revoked.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ `Connect ${ site.name }` }
      description="A key is shown once, when it is issued. Copy it then: there is nowhere to look it up afterwards, and a lost key means issuing a new one."
      width={ 560 }
      testId="bwx-clients-key-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-clients-key-issue" disabled={ busy } onClick={ () => void issue() }>
            { live ? 'Rotate key' : 'Issue key' }
          </Button>
          { live && (
            <Button variant="danger" data-testid="bwx-clients-key-revoke" disabled={ busy } onClick={ () => void revoke() }>
              Revoke key
            </Button>
          ) }
          <Button variant="ghost" data-testid="bwx-clients-key-close" onClick={ onClose }>
            Done
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-clients-key-notice" role="status">
          { notice }
        </p>
      ) }

      <p className="bwx-hint" data-testid="bwx-clients-key-state">
        <Tag tone={ connectionTone( integration ) }>{ connectionLabel( integration ) }</Tag>
        { integration && integration.key_issued_at > 0 && ` · key issued ${ dateOf( integration.key_issued_at ) }` }
        { integration && integration.key_rotated_at > 0 && `, rotated ${ dateOf( integration.key_rotated_at ) }` }
        { integration && 'revoked' === integration.key_state && integration.key_revoked_at > 0 && `, revoked ${ dateOf( integration.key_revoked_at ) }` }
        { integration && integration.last_seen_at > 0 && ` · last seen ${ dateOf( integration.last_seen_at ) }` }
      </p>

      { issued && (
        <>
          <p className="bwx-notice" data-tone="warn" data-testid="bwx-clients-key-once" role="status">
            { issued.rotated ? 'A new key was issued. ' : '' }
            Copy both now and paste them into the client site. This key cannot be shown again.
          </p>
          <CopyField label="Site id" value={ issued.integration.registry_site_id } testId="bwx-clients-key-site-id" copyTestId="bwx-clients-key-copy-site" />
          <CopyField label="Key" value={ issued.key } testId="bwx-clients-key-value" copyTestId="bwx-clients-key-copy" />
        </>
      ) }
    </Modal>
  );
}
