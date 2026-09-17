import { useEffect, useState } from 'react';
import { Users } from 'lucide-react';
import type { GrantOption, GrantsAnswer, Membership, PersonAnswer, PersonRecord, UnlinkedAccount } from '../types';
import { api, ApiError, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Select, Tag, TextInput } from '../kit';
import type { Column } from '../kit';
import { Screen } from './States';

/**
 * Everyone, and everywhere they work (PR 3 of spec 2026-09-16), in the app.
 *
 * The third configuration screen to leave WordPress admin. One person, one
 * card, with their memberships beneath their name — the shape the admin page
 * settled on, because the question this screen answers is "who reaches
 * what", and a flat table of memberships makes somebody read every row to
 * find one person.
 *
 * Every write answers the person it was made to, with their memberships, so
 * the card is replaced from the answer rather than the whole list re-read.
 */

/** A write's answer, folded into the shape the list holds. */
function fold( answer: PersonAnswer ): PersonRecord {
  return { ...answer.user, memberships: answer.memberships };
}

/** The grants a stored column holds. */
export function heldGrants( stored: string ): string[] {
  return stored.split( ',' ).map( ( one ) => one.trim() ).filter( ( one ) => '' !== one );
}

/** "Principal — may review their own work" → "Principal". The rest is the form's to explain. */
export function shortLabel( label: string ): string {
  return label.split( ' — ' )[ 0 ];
}

/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

function byName( a: PersonRecord, b: PersonRecord ): number {
  return a.display_name.localeCompare( b.display_name );
}

type Opened =
  | { kind: 'add' }
  | { kind: 'account' }
  | { kind: 'edit'; id: string };

export function PeopleScreen( { person }: { person: string } ) {
  const [ people, setPeople ] = useState< PersonRecord[] >( [] );
  const [ grants, setGrants ] = useState< GrantsAnswer | null >( null );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const [ everyone, setEveryone ] = useState( false );
  // A link can land on somebody: their panel opens once the list has them.
  const [ opened, setOpened ] = useState< Opened | null >( '' === person ? null : { kind: 'edit', id: person } );
  const [ busy, setBusy ] = useState( false );

  const target = opened && 'id' in opened ? people.find( ( one ) => one.id === opened.id ) ?? null : null;
  const shown = everyone ? people : people.filter( ( one ) => 'active' === one.status );

  /** A write's answer is the person it was made to, so their card is replaced rather than the list re-read. */
  function landed( answer: PersonAnswer, said = '' ) {
    const fresh = fold( answer );

    setPeople( ( current ) => [ ...current.filter( ( one ) => one.id !== fresh.id ), fresh ].sort( byName ) );
    setOpened( null );
    setNotice( said );
  }

  async function load() {
    setNotice( '' );

    try {
      const [ fresh, known ] = await Promise.all( [
        api< { ok: true; users: PersonRecord[] } >( '/users?status=all&with=memberships' ),
        api< GrantsAnswer >( '/grants' ),
      ] );

      setPeople( [ ...fresh.users ].sort( byName ) );
      setGrants( known );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'The people could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  useLiveReload( () => load() );

  return (
    <div className="bwx-people" data-testid="bwx-people">
      { 'loading' === state && <Screen state="loading" testId="bwx-people-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-people-state" detail="People are configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-people-state" detail={ notice } /> }

      { 'ready' === state && (
        <>
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-people-notice" role="status">
              { notice }
            </p>
          ) }

          <div className="bwx-moves">
            <Button size="sm" data-testid="bwx-people-add" disabled={ busy } onClick={ () => setOpened( { kind: 'add' } ) }>
              Add somebody new
            </Button>
            <Button size="sm" variant="secondary" data-testid="bwx-people-add-from-account" disabled={ busy } onClick={ () => setOpened( { kind: 'account' } ) }>
              Add someone who already has an account
            </Button>
            <Button size="sm" variant="ghost" data-testid="bwx-people-show-all" aria-pressed={ everyone } onClick={ () => setEveryone( ( on ) => ! on ) }>
              { everyone ? 'Show active only' : 'Show everyone, including offboarded' }
            </Button>
            <span data-testid="bwx-people-count" data-count={ shown.length } className="bwx-visually-hidden">
              { everyone ? `${ shown.length } people, including offboarded` : `${ shown.length } active people` }
            </span>
          </div>

          { 0 === shown.length ? (
            <EmptyState icon={ Users } title="Nobody yet" body={ everyone ? 'Add somebody and they will appear here.' : 'Nobody is active. Add somebody, or show everyone to see who has been offboarded.' } />
          ) : (
            <div className="bwx-people-cards" data-testid="bwx-people-cards">
              { shown.map( ( one ) => (
                <PersonCard
                  key={ one.id }
                  person={ one }
                  everyone={ everyone }
                  busy={ busy }
                  onEdit={ () => setOpened( { kind: 'edit', id: one.id } ) }
                />
              ) ) }
            </div>
          ) }

          { opened && 'add' === opened.kind && <AddForm onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'account' === opened.kind && <FromAccountForm onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'edit' === opened.kind && target && grants && (
            <EditForm person={ target } grants={ grants.on_user } onClose={ () => setOpened( null ) } onSaved={ landed } />
          ) }
        </>
      ) }
    </div>
  );
}

/** One person: who they are, how they sign in, and everywhere they work. */
function PersonCard( {
  person,
  everyone,
  busy,
  onEdit,
}: {
  person: PersonRecord;
  everyone: boolean;
  busy: boolean;
  onEdit: () => void;
} ) {
  const active = 'active' === person.status;
  const memberships = everyone ? person.memberships : person.memberships.filter( ( one ) => 'active' === one.status );

  const columns: Column< Membership >[] = [
    { key: 'client', label: 'Client', wrap: true, render: ( m ) => m.client_name || 'Unknown client' },
    { key: 'role', label: 'Role', width: 130, wrap: true, render: ( m ) => m.role_label },
    { key: 'reaches', label: 'Reaches', wrap: true, render: ( m ) => ( '' === m.client_site_id ? 'every site' : `one site: ${ m.site_name ?? 'unknown' }` ) },
    {
      key: 'status',
      label: 'Status',
      width: 90,
      render: ( m ) => ( 'active' === m.status ? <Tag tone="ok">Active</Tag> : <Tag tone="neutral">Ended</Tag> ),
    },
    { key: 'grants', label: 'Grants', wrap: true, render: ( m ) => heldGrants( m.grants ).map( shortLabel ).join( ', ' ) || '—' },
  ];

  return (
    <div data-testid="bwx-people-card" data-person={ person.id }>
      <Panel
        title={
          <>
            { person.display_name } { active ? <Tag tone="ok">Active</Tag> : <Tag tone="neutral">Offboarded</Tag> }
          </>
        }
        right={
          <div className="bwx-moves">
            <Button size="sm" variant="ghost" data-testid="bwx-people-edit" disabled={ busy } onClick={ onEdit }>
              Edit
            </Button>
          </div>
        }
      >
        <p className="bwx-hint">
          <span data-testid="bwx-people-card-email">{ person.email }</span>
          { ' · ' }
          <span data-testid="bwx-people-card-account">{ person.account ? `Signs in as ${ person.account.login }` : 'No WordPress account — they cannot sign in.' }</span>
        </p>
        <DataView< Membership >
          columns={ columns }
          rows={ memberships }
          sortable={ false }
          fixed
          empty="No client access yet"
          testId="bwx-people-memberships"
        />
      </Panel>
    </div>
  );
}

/** Somebody new: a name and an address, and WordPress makes them an account. */
function AddForm( { onClose, onSaved }: { onClose: () => void; onSaved: ( answer: PersonAnswer, said?: string ) => void } ) {
  const [ name, setName ] = useState( '' );
  const [ email, setEmail ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved( await api< PersonAnswer >( '/users', { method: 'POST', body: { display_name: name, email } } ) );
    } catch ( error ) {
      setNotice( refusal( error, 'That person could not be added.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="Add somebody new"
      width={ 520 }
      testId="bwx-people-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-form-save" disabled={ busy } onClick={ () => void save() }>
            Add person
          </Button>
          <Button variant="ghost" data-testid="bwx-people-form-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-form-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-people-form-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>
      <Field label="Email" required help="One person, one address, however many clients they work with. They get a WordPress account and an email to set their password.">
        { ( id ) => <TextInput id={ id } type="email" maxLength={ 191 } data-testid="bwx-people-form-email" value={ email } onChange={ ( event ) => setEmail( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}

/** Somebody who already signs in here: their name and address come from the account. */
function FromAccountForm( { onClose, onSaved }: { onClose: () => void; onSaved: ( answer: PersonAnswer, said?: string ) => void } ) {
  const [ accounts, setAccounts ] = useState< UnlinkedAccount[] | null >( null );
  const [ picked, setPicked ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  useEffect( () => {
    api< { ok: true; accounts: UnlinkedAccount[] } >( '/accounts' )
      .then( ( answer ) => setAccounts( answer.accounts ) )
      .catch( ( error: unknown ) => {
        setAccounts( [] );
        setNotice( messageFor( error, 'The accounts could not be read.' ) );
      } );
  }, [] );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved( await api< PersonAnswer >( '/users/from-account', { method: 'POST', body: { wp_user_id: Number( picked ) } } ) );
    } catch ( error ) {
      setNotice( refusal( error, 'That person could not be added.' ) );
    } finally {
      setBusy( false );
    }
  }

  const nobody = null !== accounts && 0 === accounts.length;

  return (
    <Modal
      title="Add someone who already has an account"
      width={ 520 }
      testId="bwx-people-account-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-account-save" disabled={ busy || nobody || '' === picked } onClick={ () => void save() }>
            Add them
          </Button>
          <Button variant="ghost" data-testid="bwx-people-account-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-account-notice" role="status">
          { notice }
        </p>
      ) }

      { nobody ? (
        <EmptyState icon={ Users } dense title="Everyone with an account is already a person" body="Add somebody new instead and Forge will make them one." />
      ) : (
        <Field label="WordPress user" required help="Their name and address come from the account they sign in with.">
          { ( id ) => (
            <Select
              id={ id }
              data-testid="bwx-people-account-pick"
              value={ picked }
              disabled={ null === accounts }
              onChange={ ( event ) => setPicked( event.target.value ) }
              options={ [
                { value: '', label: null === accounts ? 'Reading the accounts…' : 'Choose somebody' },
                ...( accounts ?? [] ).map( ( one ) => ( { value: String( one.id ), label: `${ one.display_name } (${ one.login })` } ) ),
              ] }
            />
          ) }
        </Field>
      ) }
    </Modal>
  );
}

/** A person's name, address, status and reach. Saved against the version they were read at. */
function EditForm( {
  person,
  grants,
  onClose,
  onSaved,
}: {
  person: PersonRecord;
  grants: GrantOption[];
  onClose: () => void;
  onSaved: ( answer: PersonAnswer, said?: string ) => void;
} ) {
  const [ name, setName ] = useState( person.display_name );
  const [ email, setEmail ] = useState( person.email );
  const [ status, setStatus ] = useState( person.status );
  const [ held, setHeld ] = useState< string[] >( () => heldGrants( person.grants ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const offboarding = 'active' === person.status && 'inactive' === status;

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< PersonAnswer >( `/users/${ person.id }`, {
          method: 'PATCH',
          body: { display_name: name, email, status, grants: held, record_version: person.record_version },
        } ),
        offboarding ? `${ name } has been offboarded. Their access to every client has ended.` : ''
      );
    } catch ( error ) {
      setNotice( refusal( error, 'That change could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  function toggle( grant: string ) {
    setHeld( ( current ) => ( current.includes( grant ) ? current.filter( ( one ) => one !== grant ) : [ ...current, grant ] ) );
  }

  return (
    <Modal
      title={ `Edit ${ person.display_name }` }
      width={ 560 }
      testId="bwx-people-edit-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-edit-save" disabled={ busy } onClick={ () => void save() }>
            Save
          </Button>
          <Button variant="ghost" data-testid="bwx-people-edit-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-edit-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Name" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-people-edit-name" value={ name } onChange={ ( event ) => setName( event.target.value ) } /> }
      </Field>
      <Field label="Email" required help={ person.account ? 'Their WordPress account follows: its name and address change with these.' : undefined }>
        { ( id ) => <TextInput id={ id } type="email" maxLength={ 191 } data-testid="bwx-people-edit-email" value={ email } onChange={ ( event ) => setEmail( event.target.value ) } /> }
      </Field>
      <Field label="Status">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-people-edit-status"
            value={ status }
            onChange={ ( event ) => setStatus( event.target.value ) }
            options={ [
              { value: 'active', label: 'Active' },
              { value: 'inactive', label: 'Offboarded' },
            ] }
          />
        ) }
      </Field>
      { offboarding && (
        <p className="bwx-hint" data-testid="bwx-people-edit-hint">
          Offboarding ends every membership they hold and they can no longer sign in. Their history stays.
        </p>
      ) }

      <fieldset className="bwx-field">
        <legend>Reach</legend>
        { grants.map( ( one ) => (
          <div key={ one.grant }>
            <label className="bwx-field-inline">
              <input type="checkbox" data-testid={ `bwx-people-edit-grant-${ one.grant }` } checked={ held.includes( one.grant ) } onChange={ () => toggle( one.grant ) } />
              <span>{ one.label }</span>
            </label>
            { '' !== one.description && <p className="bwx-hint">{ one.description }</p> }
          </div>
        ) ) }
      </fieldset>
    </Modal>
  );
}
