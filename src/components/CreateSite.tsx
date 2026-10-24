import { useEffect, useState } from 'react';
import { api, messageFor } from '../api';
import { Button, Field, Tag, TextInput } from '../kit';
import type { Tone } from '../kit';

type Step = 'create' | 'link' | 'people';
type StepState = '' | 'running' | 'done' | 'failed';

interface Deploy {
  slug: string;
  title: string;
  url: string;
  admin_url: string;
  steps: Record< Step, '' | 'done' | 'failed' >;
  error: string;
  people: Array< { email: string; outcome: string; reason: string } >;
}

interface DeployAnswer {
  available: boolean;
  network: { connected: boolean; name: string; domain: string; path: string; subdomains: boolean };
  deploy: Deploy;
}

const STEPS: Array< { id: Step; label: string } > = [
  { id: 'create', label: 'Site created on the network' },
  { id: 'link', label: 'Linked to this Forge site' },
  { id: 'people', label: 'Logins sent to the client\'s people' },
];

const TONE: Record< StepState, Tone > = { '': 'neutral', running: 'info', done: 'ok', failed: 'danger' };
const WORD: Record< StepState, string > = { '': 'Waiting', running: 'Working…', done: 'Done', failed: 'Failed' };

/** "acme" → "acme.network.test" or "network.test/acme", as the network places it. */
function where( network: DeployAnswer[ 'network' ], slug: string ): string {
  const name = '' === slug ? 'address' : slug;

  return network.subdomains ? `${ name }.${ network.domain }` : `${ network.domain }${ network.path }${ name }`;
}

/**
 * #621. Creates a Forge site's WordPress site on the studio's multisite
 * network, for an administrator: the site, the link back to Forge, and the
 * client's logins, a step at a time so each shows as it happens. A step that
 * fails says so and is tried again from there; what was done stays done.
 */
export function CreateSite( { siteId, siteName }: { siteId: string; siteName: string } ) {
  const [ answer, setAnswer ] = useState< DeployAnswer | null >( null );
  const [ open, setOpen ] = useState( false );
  const [ slug, setSlug ] = useState( '' );
  const [ title, setTitle ] = useState( siteName );
  const [ running, setRunning ] = useState< Step | '' >( '' );
  const [ notice, setNotice ] = useState( '' );

  useEffect( () => {
    void api< DeployAnswer >( `/client-sites/${ siteId }/deploy`, { cache: 'fresh' } )
      .then( ( next ) => {
        setAnswer( next );
        if ( '' !== next.deploy.slug ) setSlug( next.deploy.slug );
        if ( '' !== next.deploy.title ) setTitle( next.deploy.title );
      } )
      .catch( () => setAnswer( null ) );
  }, [ siteId ] );

  if ( null === answer ) return null;

  const deploy = answer.deploy;
  const started = '' !== deploy.steps.create;
  const finished = 'done' === deploy.steps.people;

  if ( ! answer.available && ! started ) return null;

  /** Runs the steps from the first one not done, stopping at a failure. */
  async function run() {
    setNotice( '' );
    let latest = answer as DeployAnswer;

    for ( const step of STEPS ) {
      if ( 'done' === latest.deploy.steps[ step.id ] ) continue;

      setRunning( step.id );
      try {
        latest = await api< DeployAnswer >( `/client-sites/${ siteId }/deploy`, { method: 'POST', body: { step: step.id, slug, title } } );
        setAnswer( latest );
      } catch ( error ) {
        setNotice( messageFor( error, 'That step did not finish.' ) );
        // Read back what was recorded, so the failed step shows as failed.
        try {
          setAnswer( await api< DeployAnswer >( `/client-sites/${ siteId }/deploy`, { cache: 'fresh' } ) );
        } catch {
          // The notice above already says what went wrong.
        }
        break;
      }
    }

    setRunning( '' );
  }

  const stateOf = ( step: Step ): StepState => ( running === step ? 'running' : deploy.steps[ step ] );
  const failed = STEPS.find( ( step ) => 'failed' === deploy.steps[ step.id ] );

  return (
    <div className="bwx-create-site" data-testid="bwx-create-site" data-site={ siteId }>
      { ! started && ! open && (
        <Button size="sm" variant="secondary" data-testid="bwx-create-site-open" onClick={ () => setOpen( true ) }>
          Create site
        </Button>
      ) }

      { ! started && open && (
        <form
          className="bwx-connection-form"
          onSubmit={ ( event ) => {
            event.preventDefault();
            void run();
          } }
        >
          <Field label="Address on the network" help={ `The new site will be at ${ where( answer.network, slug ) }` }>
            { ( id ) => (
              <TextInput
                id={ id }
                data-testid="bwx-create-site-slug"
                autoComplete="off"
                value={ slug }
                onChange={ ( event ) => setSlug( event.target.value.toLowerCase().replace( /[^a-z0-9-]/g, '' ) ) }
              />
            ) }
          </Field>
          <Field label="Site title">
            { ( id ) => <TextInput id={ id } data-testid="bwx-create-site-title" value={ title } onChange={ ( event ) => setTitle( event.target.value ) } /> }
          </Field>
          <div className="bwx-moves">
            <Button size="sm" type="submit" disabled={ '' !== running || '' === slug || '' === title.trim() } data-testid="bwx-create-site-go">
              Create site
            </Button>
            <Button size="sm" variant="ghost" disabled={ '' !== running } onClick={ () => setOpen( false ) }>
              Cancel
            </Button>
          </div>
        </form>
      ) }

      { ( started || '' !== running ) && (
        <ol className="bwx-create-site-steps" data-testid="bwx-create-site-steps" aria-live="polite">
          { STEPS.map( ( step ) => (
            <li key={ step.id } data-testid={ `bwx-create-site-step-${ step.id }` } data-state={ stateOf( step.id ) }>
              <Tag tone={ TONE[ stateOf( step.id ) ] }>{ WORD[ stateOf( step.id ) ] }</Tag>
              <span>{ step.label }</span>
            </li>
          ) ) }
        </ol>
      ) }

      { ( '' !== notice || ( '' !== deploy.error && '' === running ) ) && (
        <p className="bwx-notice" role="alert" data-testid="bwx-create-site-notice">
          { '' !== notice ? notice : deploy.error }
        </p>
      ) }

      { started && ! finished && '' === running && (
        <Button size="sm" variant="secondary" data-testid="bwx-create-site-retry" onClick={ () => void run() }>
          { failed ? `Try again from: ${ failed.label.toLowerCase() }` : 'Carry on' }
        </Button>
      ) }

      { '' !== deploy.url && 'done' === deploy.steps.create && (
        <p className="bwx-moves" data-testid="bwx-create-site-links">
          <a href={ deploy.url } target="_blank" rel="noreferrer">
            Open the site
          </a>
          <a href={ deploy.admin_url } target="_blank" rel="noreferrer">
            Open its admin
          </a>
        </p>
      ) }

      { 0 < deploy.people.length && (
        <ul className="bwx-hint" data-testid="bwx-create-site-people">
          { deploy.people.map( ( person ) => (
            <li key={ person.email }>
              { `${ person.email }: ${ { created: 'new login, set-password email sent', added: 'already on the network, added to the site', failed: `not added (${ person.reason })` }[ person.outcome ] ?? person.outcome }` }
            </li>
          ) ) }
        </ul>
      ) }
    </div>
  );
}
