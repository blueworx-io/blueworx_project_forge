import { useEffect, useRef, useState } from 'react';
import { ExternalLink } from 'lucide-react';
import { messageFor } from '../api';

/** What the item detail says about GitHub (#681). */
export interface GitHubView {
  connected: boolean;
  link: { repo: string; number: number; url: string } | null;
  site_repos: string[];
  repos: string[];
}

const NONE = 'none';

/**
 * #681. The repo a feature or sub-feature belongs in, chosen from its panel
 * and saved the moment it is picked (#544). The site's own repos come first,
 * then the account's other repos, then No repo needed. Once the task has its
 * GitHub issue the repo is fixed, so the issue is shown instead.
 */
export function RepoPicker( {
  repo,
  github,
  save,
}: {
  /** The repo as saved: '' unanswered, 'none', or owner/name. */
  repo: string;
  github: GitHubView;
  /** Writes the repo; rejects with the refusal when it is not taken. */
  save: ( repo: string ) => Promise< void >;
} ) {
  const [ value, setValue ] = useState( repo );
  const [ error, setError ] = useState( '' );
  const select = useRef< HTMLSelectElement >( null );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setValue( repo );
  }, [ repo ] );

  // Nothing chosen yet shows as blank, not as the first repo in the list.
  useEffect( () => {
    if ( select.current && '' === value ) {
      select.current.selectedIndex = -1;
    }
  } );

  if ( github.link ) {
    const name = `${ github.link.repo }#${ github.link.number }`;

    return (
      <div className="bwx-field">
        <span>Repo</span>
        <a data-testid="bwx-item-issue" href={ github.link.url } target="_blank" rel="noopener noreferrer" aria-label={ `Open ${ name } in a new tab` }>
          { name } <ExternalLink size={ 14 } aria-hidden="true" />
        </a>
      </div>
    );
  }

  // Without GitHub there is nothing to choose from but No repo needed.
  const site = github.connected ? github.site_repos : [];
  const others = github.connected ? github.repos.filter( ( one ) => ! site.includes( one ) ) : [];
  // A repo chosen earlier that has since left the account is still where this sits.
  const kept = '' !== value && NONE !== value && ! site.includes( value ) && ! others.includes( value ) ? [ value ] : [];

  async function pick( next: string ) {
    const was = value;

    setValue( next );
    setError( '' );

    try {
      await save( next );
    } catch ( failure ) {
      setValue( was );
      setError( messageFor( failure, 'That repo could not be saved.' ) );
    }
  }

  return (
    <div className="bwx-field">
      <label htmlFor="bwx-item-repo">Repo</label>
      <select id="bwx-item-repo" ref={ select } className="fk-input" data-testid="bwx-item-repo" value={ value } onChange={ ( event ) => void pick( event.target.value ) }>
        { 0 < site.length && (
          <optgroup label="This site's repos">
            { site.map( ( one ) => (
              <option key={ one } value={ one }>
                { one }
              </option>
            ) ) }
          </optgroup>
        ) }
        { ( 0 < others.length || 0 < kept.length ) && (
          <optgroup label={ github.connected ? 'Other repos on the account' : 'Chosen repo' }>
            { [ ...kept, ...others ].map( ( one ) => (
              <option key={ one } value={ one } disabled={ ! github.connected }>
                { one }
              </option>
            ) ) }
          </optgroup>
        ) }
        <option value={ NONE }>No repo needed</option>
      </select>
      { '' !== error && (
        <p className="bwx-hint bwx-field-error" role="alert" data-testid="bwx-field-error" data-field="github_repo">
          { error }
        </p>
      ) }
    </div>
  );
}
