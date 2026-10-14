import { useEffect, useState } from 'react';
import { Handshake } from 'lucide-react';
import type { ClientContact, ClientRecord, ClientRow } from '../types';
import { api, ApiError, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Tag, TextInput } from '../kit';
import type { Column } from '../kit';
import { failed, NOTHING_SAID, Notice, ok, Screen } from './States';
import type { Said } from './States';
import { ContactForm, contactLabel } from './ClientsScreen';

/**
 * Leads (#492): the businesses we are talking to, before they are clients.
 *
 * A lead moves Lead → Opportunity → Client, or into the archive, and can come
 * back out of it. It holds the same details as a client, and it is one
 * "Make client" away from being one — the same record, now a client, so
 * nothing is typed twice. Until then it appears nowhere a client does.
 */

type Opened = { kind: 'add' } | { kind: 'edit' } | { kind: 'contact' };

/** What each status is called on screen. */
const STATUS: Record< string, { label: string; tone: 'info' | 'ok' | 'neutral' } > = {
  lead: { label: 'Lead', tone: 'info' },
  opportunity: { label: 'Opportunity', tone: 'ok' },
  'lead-archived': { label: 'Archived', tone: 'neutral' },
};

function byName( a: ClientRecord, b: ClientRecord ): number {
  return a.display_name.localeCompare( b.display_name, undefined, { sensitivity: 'base' } );
}

function refusal( error: unknown, fallback: string ): string {
  if ( error instanceof ApiError ) {
    const fields = ( error.data as { fields?: Record< string, string > } | undefined )?.fields;

    if ( fields ) {
      const first = Object.values( fields )[ 0 ];

      if ( first ) {
        return first;
      }
    }
  }

  return messageFor( error, fallback );
}

export function LeadsScreen() {
  const [ leads, setLeads ] = useState< ClientRecord[] >( [] );
  const [ archive, setArchive ] = useState( false );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState< Said >( NOTHING_SAID );
  const [ busy, setBusy ] = useState( false );
  const [ selectedId, setSelectedId ] = useState< string | null >( null );
  const [ opened, setOpened ] = useState< Opened | null >( null );

  async function load( archived = archive ) {
    setNotice( NOTHING_SAID );

    try {
      const fresh = await api< { ok: true; leads: ClientRecord[] } >( `/leads?archived=${ archived ? '1' : '0' }` );

      setLeads( [ ...fresh.leads ].sort( byName ) );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( failed( messageFor( error, 'The leads could not be read.' ) ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  const selected = leads.find( ( lead ) => lead.id === selectedId ) ?? null;

  /** One move of the selected lead, then the list again. */
  async function move( lead: ClientRecord, status: string, said: string ) {
    setBusy( true );

    try {
      await api< { ok: true; lead: ClientRow } >( `/leads/${ lead.id }`, { method: 'PATCH', body: { status, record_version: lead.record_version } } );
      // The list first, then the word: a reload starts by clearing the notice.
      await load();
      setNotice( ok( said ) );
    } catch ( error ) {
      setNotice( failed( refusal( error, 'That could not be changed.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  async function makeClient( lead: ClientRecord ) {
    setBusy( true );

    try {
      await api< { ok: true; client: ClientRow } >( `/leads/${ lead.id }/convert`, { method: 'POST', body: { record_version: lead.record_version } } );
      setSelectedId( null );
      await load();
      setNotice( ok( `${ lead.display_name } is now a client. Find them under Clients.` ) );
    } catch ( error ) {
      setNotice( failed( refusal( error, 'That could not be made a client.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  async function showArchive( on: boolean ) {
    setArchive( on );
    setSelectedId( null );
    await load( on );
  }

  const columns: Column< ClientRecord >[] = [
    { key: 'name', label: 'Name', wrap: true, render: ( lead ) => lead.display_name },
    {
      key: 'status',
      label: 'Status',
      width: 130,
      render: ( lead ) => {
        const status = STATUS[ lead.status ] ?? { label: lead.status, tone: 'neutral' as const };

        return <Tag tone={ status.tone }>{ status.label }</Tag>;
      },
    },
    { key: 'contact', label: 'Contact', wrap: true, render: ( lead ) => contactLabel( lead.contact ) },
    { key: 'timezone', label: 'Timezone', mono: true, width: 160, render: ( lead ) => lead.timezone },
  ];

  return (
    <div className="bwx-clients" data-testid="bwx-leads">
      { 'loading' === state && <Screen state="loading" testId="bwx-leads-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-leads-state" detail="Leads are the administrator's to manage." /> }
      { 'error' === state && <Screen state="error" testId="bwx-leads-state" detail={ notice.text } /> }

      { 'ready' === state && (
        <>
          <Notice said={ notice } testId="bwx-leads-notice" />

          <Panel
            title={ archive ? 'Leads that did not sign' : 'Who we are talking to' }
            flush
            right={
              <div className="bwx-moves">
                <Button size="sm" variant="ghost" data-testid="bwx-leads-show-archive" aria-pressed={ archive } onClick={ () => void showArchive( ! archive ) }>
                  { archive ? 'Show leads in play' : 'Show the archive' }
                </Button>
                <Button size="sm" data-testid="bwx-leads-add" disabled={ busy } onClick={ () => setOpened( { kind: 'add' } ) }>
                  Add a lead
                </Button>
              </div>
            }
          >
            <DataView< ClientRecord >
              bare
              columns={ columns }
              rows={ leads }
              sortable={ false }
              fixed
              selectedId={ selectedId }
              onRowClick={ ( lead ) => setSelectedId( lead.id ) }
              empty={
                <EmptyState
                  icon={ Handshake }
                  dense
                  title={ archive ? 'Nothing archived' : 'No leads yet' }
                  body={ archive ? 'A lead that does not sign is archived here, and can be brought back.' : 'Add a business you are talking to. Once they sign, make them a client from here.' }
                />
              }
              footer={ archive ? `${ leads.length } archived` : `${ leads.length } in play` }
              testId="bwx-leads-list"
            />
          </Panel>

          { selected && (
            <div data-testid="bwx-leads-selected" data-lead={ selected.id }>
              <Panel
                title={ selected.display_name }
                flush
                right={
                  <div className="bwx-moves">
                    <Button size="sm" variant="ghost" data-testid="bwx-leads-edit" disabled={ busy } onClick={ () => setOpened( { kind: 'edit' } ) }>
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" data-testid="bwx-leads-contact" disabled={ busy } onClick={ () => setOpened( { kind: 'contact' } ) }>
                      Contact
                    </Button>
                    { 'lead' === selected.status && (
                      <Button size="sm" variant="ghost" data-testid="bwx-leads-forward" disabled={ busy } onClick={ () => void move( selected, 'opportunity', `${ selected.display_name } is now an opportunity.` ) }>
                        Move to opportunity
                      </Button>
                    ) }
                    { 'opportunity' === selected.status && (
                      <>
                        <Button size="sm" variant="ghost" data-testid="bwx-leads-back" disabled={ busy } onClick={ () => void move( selected, 'lead', `${ selected.display_name } is a lead again.` ) }>
                          Back to lead
                        </Button>
                        <Button size="sm" data-testid="bwx-leads-convert" disabled={ busy } onClick={ () => void makeClient( selected ) }>
                          Make client
                        </Button>
                      </>
                    ) }
                    { 'lead-archived' === selected.status ? (
                      <Button size="sm" variant="ghost" data-testid="bwx-leads-restore" disabled={ busy } onClick={ () => void move( selected, 'lead', `${ selected.display_name } is back in play.` ) }>
                        Restore
                      </Button>
                    ) : (
                      <Button size="sm" variant="ghost" data-testid="bwx-leads-archive" disabled={ busy } onClick={ () => void move( selected, 'lead-archived', `${ selected.display_name } has been archived.` ) }>
                        Archive
                      </Button>
                    ) }
                  </div>
                }
              >
                <p className="bwx-hint bwx-panel-lead" data-testid="bwx-leads-selected-detail">
                  { selected.legal_name && `${ selected.legal_name } · ` }
                  { selected.timezone }
                  { 0 < selected.email_domains.length && ` · ${ selected.email_domains.join( ', ' ) }` }
                  { ' · ' }
                  <span data-testid="bwx-leads-selected-contact">{ `Contact: ${ contactLabel( selected.contact ) }` }</span>
                </p>
              </Panel>
            </div>
          ) }

          { opened && 'contact' !== opened.kind && (
            <LeadForm
              editing={ 'edit' === opened.kind && selected ? selected : undefined }
              onClose={ () => setOpened( null ) }
              onSaved={ ( lead, said ) => {
                setOpened( null );
                setSelectedId( lead.id );
                void load().then( () => setNotice( ok( said ) ) );
              } }
            />
          ) }

          { opened && 'contact' === opened.kind && selected && (
            <ContactForm
              client={ selected }
              onClose={ () => setOpened( null ) }
              onSaved={ ( contact: ClientContact ) => {
                setOpened( null );
                setLeads( ( known ) => known.map( ( lead ) => ( lead.id === selected.id ? { ...lead, contact } : lead ) ) );
                setNotice( ok( `${ contactLabel( contact ) } now looks after ${ selected.display_name }.` ) );
              } }
            />
          ) }
        </>
      ) }
    </div>
  );
}

/** Add or edit a lead: the same details a client holds, less its staff. */
function LeadForm( {
  editing,
  onClose,
  onSaved,
}: {
  editing?: ClientRecord;
  onClose: () => void;
  onSaved: ( lead: ClientRow, said: string ) => void;
} ) {
  const [ name, setName ] = useState( editing?.display_name ?? '' );
  const [ legal, setLegal ] = useState( editing?.legal_name ?? '' );
  const [ timezone, setTimezone ] = useState( editing?.timezone ?? 'Europe/London' );
  const [ domains, setDomains ] = useState( editing?.email_domains.join( ', ' ) ?? '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = { display_name: name, legal_name: legal, timezone, email_domains: domains };

    try {
      if ( editing ) {
        const answer = await api< { ok: true; lead: ClientRow } >( `/leads/${ editing.id }`, { method: 'PATCH', body: { ...body, record_version: editing.record_version } } );

        onSaved( answer.lead, `${ answer.lead.display_name } saved.` );
      } else {
        const answer = await api< { ok: true; lead: ClientRow } >( '/leads', { method: 'POST', body } );

        onSaved( answer.lead, `${ answer.lead.display_name } added as a lead.` );
      }
    } catch ( error ) {
      setNotice( refusal( error, 'That could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ editing ? `Edit ${ editing.display_name }` : 'Add a lead' }
      width={ 520 }
      testId="bwx-leads-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-leads-form-save" disabled={ busy } onClick={ () => void save() }>
            { editing ? 'Save' : 'Add lead' }
          </Button>
          <Button variant="ghost" data-testid="bwx-leads-form-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-leads-form-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-leads-form-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>
      <Field label="Legal name" help="If it differs from the name we know them by.">
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-leads-form-legal" value={ legal } onChange={ ( event ) => setLegal( event.target.value ) } /> }
      </Field>
      <Field label="Timezone" required help="As the tz database names it, such as Europe/London.">
        { ( id ) => <TextInput id={ id } maxLength={ 64 } data-testid="bwx-leads-form-timezone" value={ timezone } onChange={ ( event ) => setTimezone( event.target.value ) } /> }
      </Field>
      <Field label="Permitted email domains" help="Each one a domain on its own, separated by commas, such as acme.co.uk, acme.com.">
        { ( id ) => <TextInput id={ id } maxLength={ 1000 } placeholder="acme.co.uk, acme.com" data-testid="bwx-leads-form-domains" value={ domains } onChange={ ( event ) => setDomains( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}
