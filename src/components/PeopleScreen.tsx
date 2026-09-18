import { useEffect, useState } from 'react';
import { Users } from 'lucide-react';
import type { Client, ClientSite, GrantOption, GrantsAnswer, Membership, PersonAnswer, PersonRecord, UnlinkedAccount } from '../types';
import { api, ApiError, forgeData, isDenied, messageFor } from '../api';
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

/** The five access roles, in the order the matrix lists them, labelled as `Roles::label` does. */
export const ROLES: Array< { value: string; label: string } > = [
  { value: 'primary_admin', label: 'Primary administrator' },
  { value: 'staff', label: 'Staff' },
  { value: 'client_admin', label: 'Client administrator' },
  { value: 'client_viewer', label: 'Client viewer' },
  { value: 'internal_viewer', label: 'Internal viewer' },
];

/** The roles held by a client's own people: the membership grants are studio authority, and not theirs to hold. */
function clientSide( role: string ): boolean {
  return 'client_admin' === role || 'client_viewer' === role;
}

type Opened =
  | { kind: 'add' }
  | { kind: 'account' }
  | { kind: 'edit'; id: string }
  | { kind: 'link'; id: string }
  | { kind: 'membership'; id: string }
  | { kind: 'grants'; id: string; membership: string };

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

  /** A confirmed action on a card. The answer is the person, so it lands like a form's. */
  async function act( question: string, write: () => Promise< PersonAnswer >, said: string, fallback: string ) {
    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      landed( await write(), said );
    } catch ( error ) {
      setNotice( messageFor( error, fallback ) );
    } finally {
      setBusy( false );
    }
  }

  function offboard( target: PersonRecord ) {
    void act(
      `Offboard ${ target.display_name }? Every membership ends and they can no longer sign in.`,
      () => api< PersonAnswer >( `/users/${ target.id }/offboard`, { method: 'POST', body: { record_version: target.record_version } } ),
      `${ target.display_name } has been offboarded. Their access to every client has ended; their history stays.`,
      'That person could not be offboarded.'
    );
  }

  /** A membership's own write answers the membership; the card wants the person, so they are read again. */
  function endMembership( target: PersonRecord, membership: Membership ) {
    void act(
      `End ${ target.display_name }'s access to ${ membership.client_name }? Their history there stays.`,
      async () => {
        await api( `/memberships/${ membership.id }`, { method: 'PATCH', body: { status: 'inactive', record_version: membership.record_version } } );

        return api< PersonAnswer >( `/users/${ target.id }` );
      },
      `${ target.display_name } no longer has access to ${ membership.client_name }.`,
      'That access could not be ended.'
    );
  }

  async function remove( target: PersonRecord ) {
    if ( ! window.confirm( `Delete ${ target.display_name } from Forge? Their WordPress account stays.` ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api< { ok: true; deleted: string } >( `/users/${ target.id }`, { method: 'DELETE' } );

      setPeople( ( current ) => current.filter( ( one ) => one.id !== target.id ) );
      setNotice( `Deleted ${ target.display_name } from Forge. Their WordPress account is still there.` );
    } catch ( error ) {
      setNotice( messageFor( error, 'That person could not be deleted.' ) );
    } finally {
      setBusy( false );
    }
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
                  known={ grants?.on_membership ?? [] }
                  busy={ busy }
                  onEdit={ () => setOpened( { kind: 'edit', id: one.id } ) }
                  onLink={ () => setOpened( { kind: 'link', id: one.id } ) }
                  onAddMembership={ () => setOpened( { kind: 'membership', id: one.id } ) }
                  onGrants={ ( membership ) => setOpened( { kind: 'grants', id: one.id, membership: membership.id } ) }
                  onEnd={ ( membership ) => endMembership( one, membership ) }
                  onOffboard={ () => offboard( one ) }
                  onDelete={ () => void remove( one ) }
                />
              ) ) }
            </div>
          ) }

          { opened && 'add' === opened.kind && <AddForm onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'account' === opened.kind && <FromAccountForm onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'edit' === opened.kind && target && grants && (
            <EditForm person={ target } grants={ grants.on_user } onClose={ () => setOpened( null ) } onSaved={ landed } />
          ) }
          { opened && 'link' === opened.kind && target && <LinkForm person={ target } onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'membership' === opened.kind && target && <MembershipForm person={ target } onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'grants' === opened.kind && target && grants && (
            <GrantsForm
              person={ target }
              membership={ target.memberships.find( ( one ) => one.id === opened.membership ) ?? null }
              grants={ grants.on_membership }
              onClose={ () => setOpened( null ) }
              onSaved={ landed }
            />
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
  known,
  busy,
  onEdit,
  onLink,
  onAddMembership,
  onGrants,
  onEnd,
  onOffboard,
  onDelete,
}: {
  person: PersonRecord;
  everyone: boolean;
  /** Every grant a membership can hold, for labelling the ones held. */
  known: GrantOption[];
  busy: boolean;
  onEdit: () => void;
  onLink: () => void;
  onAddMembership: () => void;
  onGrants: ( membership: Membership ) => void;
  onEnd: ( membership: Membership ) => void;
  onOffboard: () => void;
  onDelete: () => void;
} ) {
  const active = 'active' === person.status;
  const memberships = everyone ? person.memberships : person.memberships.filter( ( one ) => 'active' === one.status );
  const adminUrl = forgeData()?.adminUrl ?? `${ forgeData()?.siteUrl ?? '' }/wp-admin/`;

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
    { key: 'grants', label: 'Grants', wrap: true, render: ( m ) => heldGrants( m.grants ).map( ( grant ) => shortLabel( known.find( ( one ) => one.grant === grant )?.label ?? grant ) ).join( ', ' ) || '—' },
    {
      key: 'actions',
      label: '',
      width: 190,
      render: ( m ) =>
        'active' === m.status && active ? (
          <span className="bwx-moves">
            { ! clientSide( m.role ) && (
              <Button size="sm" variant="ghost" data-testid="bwx-people-membership-grants" aria-label={ `Grants with ${ m.client_name }` } disabled={ busy } onClick={ () => onGrants( m ) }>
                Grants
              </Button>
            ) }
            <Button size="sm" variant="ghost" data-testid="bwx-people-membership-end" aria-label={ `End access to ${ m.client_name }` } disabled={ busy } onClick={ () => onEnd( m ) }>
              End
            </Button>
          </span>
        ) : null,
    },
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
            { ! person.account && (
              <Button size="sm" variant="ghost" data-testid="bwx-people-link" disabled={ busy } onClick={ onLink }>
                Give them an account
              </Button>
            ) }
            { active && (
              <Button size="sm" variant="ghost" data-testid="bwx-people-add-membership" disabled={ busy } onClick={ onAddMembership }>
                Add access
              </Button>
            ) }
            { active ? (
              <Button size="sm" variant="ghost" data-testid="bwx-people-offboard" disabled={ busy } onClick={ onOffboard }>
                Offboard
              </Button>
            ) : (
              <Button size="sm" variant="ghost" data-testid="bwx-people-delete" disabled={ busy } onClick={ onDelete }>
                Delete
              </Button>
            ) }
          </div>
        }
      >
        <p className="bwx-hint">
          <span data-testid="bwx-people-card-email">{ person.email }</span>
          { ' · ' }
          <span data-testid="bwx-people-card-account">
            { person.account ? (
              <a href={ `${ adminUrl }user-edit.php?user_id=${ person.wp_user_id }` }>{ `Signs in as ${ person.account.login }` }</a>
            ) : (
              'No WordPress account — they cannot sign in.'
            ) }
          </span>
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
      onSaved( await api< PersonAnswer >( '/users', { method: 'POST', body: { display_name: name, email, make_account: true } } ) );
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

/** An account for somebody who has none: one nobody holds, or a new one made from their record. */
function LinkForm( {
  person,
  onClose,
  onSaved,
}: {
  person: PersonRecord;
  onClose: () => void;
  onSaved: ( answer: PersonAnswer, said?: string ) => void;
} ) {
  const [ accounts, setAccounts ] = useState< UnlinkedAccount[] | null >( null );
  const [ picked, setPicked ] = useState( '0' );
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
      onSaved(
        await api< PersonAnswer >( `/users/${ person.id }/account`, {
          method: 'POST',
          body: { wp_user_id: Number( picked ), record_version: person.record_version },
        } ),
        '0' === picked ? `${ person.display_name } has a WordPress account now.` : ''
      );
    } catch ( error ) {
      setNotice( refusal( error, 'That account could not be given.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ `Give ${ person.display_name } an account` }
      width={ 520 }
      testId="bwx-people-link-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-link-save" disabled={ busy || null === accounts } onClick={ () => void save() }>
            Save account
          </Button>
          <Button variant="ghost" data-testid="bwx-people-link-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-link-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="WordPress user" help="An existing account keeps its own name and address. A new one takes theirs.">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-people-link-pick"
            value={ picked }
            disabled={ null === accounts }
            onChange={ ( event ) => setPicked( event.target.value ) }
            options={ [
              { value: '0', label: 'Make them a new one' },
              ...( accounts ?? [] ).map( ( one ) => ( { value: String( one.id ), label: `${ one.display_name } (${ one.login })` } ) ),
            ] }
          />
        ) }
      </Field>
    </Modal>
  );
}

/** A role with a client, reaching every site the client has or one of them. */
function MembershipForm( {
  person,
  onClose,
  onSaved,
}: {
  person: PersonRecord;
  onClose: () => void;
  onSaved: ( answer: PersonAnswer, said?: string ) => void;
} ) {
  const [ clients, setClients ] = useState< Client[] | null >( null );
  const [ sites, setSites ] = useState< ClientSite[] >( [] );
  const [ clientId, setClientId ] = useState( '' );
  const [ role, setRole ] = useState( 'staff' );
  const [ siteId, setSiteId ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  useEffect( () => {
    api< { ok: true; clients: Client[] } >( '/clients' )
      .then( ( answer ) => setClients( answer.clients ) )
      .catch( ( error: unknown ) => {
        setClients( [] );
        setNotice( messageFor( error, 'The clients could not be read.' ) );
      } );
  }, [] );

  // The sites follow the client: a site of one client means nothing on another.
  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setSites( [] );
    setSiteId( '' );

    if ( '' === clientId ) {
      return;
    }

    let live = true;

    api< { ok: true; sites: ClientSite[] } >( `/clients/${ clientId }/sites` )
      .then( ( answer ) => {
        if ( live ) {
          setSites( answer.sites );
        }
      } )
      .catch( () => undefined );

    return () => {
      live = false;
    };
  }, [ clientId ] );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      await api( `/clients/${ clientId }/memberships`, { method: 'POST', body: { user_id: person.id, role, client_site_id: siteId } } );

      // The membership's own answer is the membership; the card wants the
      // person with everywhere they work, so they are read again.
      onSaved( await api< PersonAnswer >( `/users/${ person.id }` ) );
    } catch ( error ) {
      setNotice( refusal( error, 'That access could not be granted.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title={ `Give ${ person.display_name } access to a client` }
      width={ 520 }
      testId="bwx-people-membership-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-membership-save" disabled={ busy || '' === clientId } onClick={ () => void save() }>
            Give access
          </Button>
          <Button variant="ghost" data-testid="bwx-people-membership-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-membership-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Client" required>
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-people-membership-client"
            value={ clientId }
            disabled={ null === clients }
            onChange={ ( event ) => setClientId( event.target.value ) }
            options={ [
              { value: '', label: null === clients ? 'Reading the clients…' : 'Choose a client' },
              ...( clients ?? [] ).map( ( one ) => ( { value: one.id, label: one.display_name } ) ),
            ] }
          />
        ) }
      </Field>
      <Field label="Role" required>
        { ( id ) => <Select id={ id } data-testid="bwx-people-membership-role" value={ role } onChange={ ( event ) => setRole( event.target.value ) } options={ ROLES } /> }
      </Field>
      <Field label="Reaches" help="Every site the client has, or one of them.">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-people-membership-site"
            value={ siteId }
            disabled={ '' === clientId }
            onChange={ ( event ) => setSiteId( event.target.value ) }
            options={ [ { value: '', label: 'Every site' }, ...sites.map( ( one ) => ( { value: one.id, label: one.name } ) ) ] }
          />
        ) }
      </Field>
    </Modal>
  );
}

/** The grants held with one client (#93): studio authority, given per membership. */
function GrantsForm( {
  person,
  membership,
  grants,
  onClose,
  onSaved,
}: {
  person: PersonRecord;
  membership: Membership | null;
  grants: GrantOption[];
  onClose: () => void;
  onSaved: ( answer: PersonAnswer, said?: string ) => void;
} ) {
  const [ held, setHeld ] = useState< string[] >( () => heldGrants( membership?.grants ?? '' ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  if ( ! membership ) {
    return null;
  }

  async function save() {
    if ( ! membership ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/memberships/${ membership.id }`, { method: 'PATCH', body: { grants: held, record_version: membership.record_version } } );

      onSaved( await api< PersonAnswer >( `/users/${ person.id }` ) );
    } catch ( error ) {
      setNotice( refusal( error, 'Those grants could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  function toggle( grant: string ) {
    setHeld( ( current ) => ( current.includes( grant ) ? current.filter( ( one ) => one !== grant ) : [ ...current, grant ] ) );
  }

  return (
    <Modal
      title={ `${ person.display_name } with ${ membership.client_name }` }
      description="What they may do there, beyond what their role gives them."
      width={ 560 }
      testId="bwx-people-grants-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-grants-save" disabled={ busy } onClick={ () => void save() }>
            Save grants
          </Button>
          <Button variant="ghost" data-testid="bwx-people-grants-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-grants-notice" role="status">
          { notice }
        </p>
      ) }

      <fieldset className="bwx-field">
        <legend>Grants</legend>
        { grants.map( ( one ) => (
          <div key={ one.grant }>
            <label className="bwx-field-inline">
              <input type="checkbox" data-testid={ `bwx-people-grant-${ one.grant }` } checked={ held.includes( one.grant ) } onChange={ () => toggle( one.grant ) } />
              <span>{ one.label }</span>
            </label>
            { '' !== one.description && <p className="bwx-hint">{ one.description }</p> }
          </div>
        ) ) }
      </fieldset>
    </Modal>
  );
}
