import { useEffect, useState } from 'react';
import type { WorkItem } from '../types';
import { messageFor } from '../api';
import { beneath, mayHold } from '../tree.mjs';
import { Select } from '../kit';

const KIND: Record< string, string > = {
  project: 'Project',
  milestone: 'Release',
  feature: 'Feature',
  'sub-feature': 'Sub-feature',
  bug: 'Bug',
  task: 'Task',
  feedback: 'Feedback',
};

/** What an item is, as a person reads it: its type for a leaf, else its level. */
const kindOf = ( one: WorkItem ) => KIND[ 'feature' === one.work_type ? one.level : one.work_type ] ?? one.level;

/**
 * #562. What an item sits under, chosen from its panel and saved the moment it
 * is picked (#544). Offers only what the server would take: nothing beneath
 * the item itself, no bug, task or feedback, and a higher level unless the
 * item is a bug, task or feedback. The panel does the writing, through its
 * own save queue, so this never quotes a version of its own.
 */
export function SitsUnder( {
  item,
  siteItems,
  save,
}: {
  item: WorkItem;
  /** The site's other open items. */
  siteItems: WorkItem[];
  /** Writes the new parent; rejects with the refusal when it is not taken. */
  save: ( parentId: string ) => Promise< void >;
} ) {
  const [ value, setValue ] = useState( item.parent_id );
  const [ error, setError ] = useState( '' );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setValue( item.parent_id );
  }, [ item.parent_id ] );

  const below = beneath( siteItems, item.id );
  const offered = siteItems.filter( ( one ) => one.id !== item.id && ! below.has( one.id ) && mayHold( one, item ) );
  const options = [
    { value: '', label: 'Nothing' },
    // A parent that has since ended is not offered, but it is still where this sits.
    ...( '' !== item.parent_id && ! offered.some( ( one ) => one.id === item.parent_id ) ? [ { value: item.parent_id, label: 'Its current parent' } ] : [] ),
    ...offered.map( ( one ) => ( { value: one.id, label: `${ kindOf( one ) }: ${ one.title }` } ) ),
  ];

  async function pick( next: string ) {
    const was = value;

    setValue( next );
    setError( '' );

    try {
      await save( next );
    } catch ( failure ) {
      setValue( was );
      setError( messageFor( failure, 'That could not be moved.' ) );
    }
  }

  return (
    <div className="bwx-field">
      <label htmlFor="bwx-sits-under">Sits under</label>
      <Select id="bwx-sits-under" data-testid="bwx-sits-under" value={ value } options={ options } onChange={ ( event ) => void pick( event.target.value ) } />
      { '' !== error && (
        <p className="bwx-hint bwx-field-error" role="alert" data-testid="bwx-field-error" data-field="parent_id">
          { error }
        </p>
      ) }
    </div>
  );
}
