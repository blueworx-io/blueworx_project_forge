import { useId, useState } from 'react';
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { Button, type ButtonVariant } from './primitives';
import { Tag } from './primitives';

/*
 * Fields, inputs and the two compound form pieces the design repeats: the
 * reason-gated action and the evidence picker.
 */

/**
 * A label, its control, and the help under it. The control is passed a
 * function so it gets the id the label points at — a field somebody cannot
 * reach by its label is not labelled.
 */
export function Field( {
  label,
  required,
  help,
  children,
}: {
  label: ReactNode;
  required?: boolean;
  help?: ReactNode;
  children: ( id: string ) => ReactNode;
} ) {
  const id = useId();

  return (
    <div className="fk-field">
      <label className="fk-field-label" htmlFor={ id }>
        { label }
        { required && <span className="fk-field-required"> *</span> }
      </label>
      { children( id ) }
      { help && <span className="fk-field-help">{ help }</span> }
    </div>
  );
}

export function TextInput( props: InputHTMLAttributes< HTMLInputElement > ) {
  return <input className="fk-input" { ...props } />;
}

export function TextArea( props: TextareaHTMLAttributes< HTMLTextAreaElement > ) {
  return <textarea className="fk-input" { ...props } />;
}

export function Select( { options, ...rest }: SelectHTMLAttributes< HTMLSelectElement > & { options: Array< { value: string; label: string } > } ) {
  return (
    <select className="fk-input" { ...rest }>
      { options.map( ( option ) => (
        <option key={ option.value } value={ option.value }>
          { option.label }
        </option>
      ) ) }
    </select>
  );
}

/**
 * A primary action that stays off until a reason is written. Returns, blocks,
 * overrides and declines all go through this — never a bare confirm.
 */
export function ReasonAction( {
  label,
  placeholder,
  onSubmit,
  variant = 'primary',
}: {
  label: string;
  placeholder?: string;
  onSubmit: ( reason: string ) => void;
  variant?: ButtonVariant;
} ) {
  const [ reason, setReason ] = useState( '' );

  return (
    <>
      <Field label="Reason" required help="Recorded verbatim in the changelog with your name and the time.">
        { ( id ) => (
          <TextArea id={ id } rows={ 3 } value={ reason } placeholder={ placeholder } onChange={ ( e ) => setReason( e.target.value ) } />
        ) }
      </Field>
      <div className="fk-actions-end">
        <Button variant={ variant } disabled={ ! reason.trim() } onClick={ () => onSubmit( reason.trim() ) }>
          { label }
        </Button>
      </div>
    </>
  );
}

/**
 * Files attached as evidence. The control is a real file input so it works
 * with a keyboard and a screen reader, and the rule about secrets is written
 * on it rather than assumed.
 */
export function Evidence( {
  files,
  onAdd,
  id,
}: {
  files: string[];
  onAdd: ( files: File[] ) => void;
  id?: string;
} ) {
  const inputId = useId();

  return (
    <div className="fk-evidence">
      <label className="fk-evidence-drop" htmlFor={ id ?? inputId }>
        Choose a file · never passwords or API secrets
        <input
          id={ id ?? inputId }
          type="file"
          multiple
          onChange={ ( e ) => {
            const picked = Array.from( e.target.files ?? [] );
            if ( picked.length ) onAdd( picked );
            e.target.value = '';
          } }
        />
      </label>
      { files.length > 0 && (
        <ul className="fk-evidence-list">
          { files.map( ( name, i ) => (
            <li key={ i }>
              <Tag tone="ok" dot>
                Attached
              </Tag>
              <span className="fk-mono">{ name }</span>
            </li>
          ) ) }
        </ul>
      ) }
    </div>
  );
}
