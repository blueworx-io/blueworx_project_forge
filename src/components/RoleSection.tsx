import { useEffect, useState } from 'react';
import type { StaffRole } from '../types';
import { api, messageFor, ApiError } from '../api';
import { Button, Field, Modal, Panel, Stat, TextArea, TextInput } from '../kit';

/**
 * What a person does here (#474): a title, hours as a note, contract dates,
 * a description and regular duties. The administrator sets it on the People
 * screen; each person reads their own on their profile. The hours change
 * nothing else in Forge.
 */

const EMPTY: StaffRole = { title: '', weekly_hours: '', starts_on: '', ends_on: '', description: '', duties: [] };

/** A refusal by field, or the fallback. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

/** The role, read only, for the person it belongs to. */
export function RolePanel( { person }: { person: string } ) {
  const [ role, setRole ] = useState< StaffRole | null >( null );

  useEffect( () => {
    api< { role: StaffRole } >( `/users/${ person }/role` )
      .then( ( answer ) => setRole( answer.role ) )
      .catch( () => setRole( null ) );
  }, [ person ] );

  if ( ! role ) {
    return null;
  }

  const empty = '' === role.title && '' === role.description && 0 === role.duties.length && '' === role.starts_on && '' === role.weekly_hours;

  return (
    <Panel title="Your role">
      <div data-testid="bwx-role-panel">
        { empty ? (
          <p className="bwx-hint" data-testid="bwx-role-empty">
            Nothing has been set for your role yet.
          </p>
        ) : (
          <>
            <div className="bwx-profile-facts">
              <Stat label="Title" value={ <span data-testid="bwx-role-title">{ role.title || '—' }</span> } />
              <Stat label="Hours a week" value={ role.weekly_hours || '—' } />
              <Stat label="Contract" value={ '' === role.starts_on ? '—' : `${ role.starts_on } to ${ role.ends_on || 'ongoing' }` } />
            </div>
            { '' !== role.description && <p data-testid="bwx-role-description">{ role.description }</p> }
            { role.duties.length > 0 && (
              <ul data-testid="bwx-role-duties">
                { role.duties.map( ( duty, index ) => (
                  <li key={ index }>{ duty }</li>
                ) ) }
              </ul>
            ) }
          </>
        ) }
      </div>
    </Panel>
  );
}

/** The administrator's form for one person's role. */
export function RoleForm( { id, name, onClose, onSaved }: { id: string; name: string; onClose: () => void; onSaved: ( said: string ) => void } ) {
  const [ role, setRole ] = useState< StaffRole | null >( null );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  useEffect( () => {
    api< { role: StaffRole } >( `/users/${ id }/role` )
      .then( ( answer ) => setRole( answer.role ) )
      .catch( ( error ) => {
        setRole( EMPTY );
        setNotice( refusal( error, 'The role could not be read.' ) );
      } );
  }, [ id ] );

  function change( patch: Partial< StaffRole > ) {
    setRole( ( current ) => ( { ...( current ?? EMPTY ), ...patch } ) );
  }

  async function save() {
    if ( ! role ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/users/${ id }/role`, { method: 'PUT', body: role } );
      onSaved( `Saved the role for ${ name }.` );
    } catch ( error ) {
      setNotice( refusal( error, 'That role could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  const duties = role?.duties ?? [];

  return (
    <Modal
      title={ `Role for ${ name }` }
      width={ 560 }
      testId="bwx-people-role-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-people-role-save" disabled={ busy || null === role } onClick={ () => void save() }>
            Save
          </Button>
          <Button variant="ghost" data-testid="bwx-people-role-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-people-role-notice" role="status">
          { notice }
        </p>
      ) }

      { role && (
        <>
          <Field label="Title">
            { ( fieldId ) => <TextInput id={ fieldId } maxLength={ 191 } data-testid="bwx-people-role-title" value={ role.title } onChange={ ( event ) => change( { title: event.target.value } ) } /> }
          </Field>
          <Field label="Hours a week" help="A note only. It does not change what Forge counts as their time.">
            { ( fieldId ) => (
              <TextInput id={ fieldId } type="number" min={ 0 } max={ 168 } step="any" data-testid="bwx-people-role-hours" value={ role.weekly_hours } onChange={ ( event ) => change( { weekly_hours: event.target.value } ) } />
            ) }
          </Field>
          <Field label="Contract starts">
            { ( fieldId ) => <TextInput id={ fieldId } type="date" data-testid="bwx-people-role-starts" value={ role.starts_on } onChange={ ( event ) => change( { starts_on: event.target.value } ) } /> }
          </Field>
          <Field label="Contract ends" help="Leave blank if the contract is ongoing.">
            { ( fieldId ) => <TextInput id={ fieldId } type="date" data-testid="bwx-people-role-ends" value={ role.ends_on } onChange={ ( event ) => change( { ends_on: event.target.value } ) } /> }
          </Field>
          <Field label="What they do">
            { ( fieldId ) => <TextArea id={ fieldId } rows={ 3 } data-testid="bwx-people-role-description" value={ role.description } onChange={ ( event ) => change( { description: event.target.value } ) } /> }
          </Field>

          <fieldset className="bwx-profile-prefs" data-testid="bwx-people-role-duties">
            <legend>Regular duties</legend>
            { duties.map( ( duty, index ) => (
              <div className="bwx-moves" key={ index }>
                <TextInput
                  maxLength={ 191 }
                  aria-label={ `Duty ${ index + 1 }` }
                  data-testid="bwx-people-role-duty"
                  value={ duty }
                  onChange={ ( event ) => change( { duties: duties.map( ( one, at ) => ( at === index ? event.target.value : one ) ) } ) }
                />
                <Button size="sm" variant="ghost" data-testid="bwx-people-role-duty-remove" aria-label={ `Remove duty ${ index + 1 }` } onClick={ () => change( { duties: duties.filter( ( _, at ) => at !== index ) } ) }>
                  Remove
                </Button>
              </div>
            ) ) }
            <Button size="sm" variant="secondary" data-testid="bwx-people-role-duty-add" onClick={ () => change( { duties: [ ...duties, '' ] } ) }>
              Add a duty
            </Button>
          </fieldset>
        </>
      ) }
    </Modal>
  );
}
