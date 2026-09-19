import { Select } from './kit';

/*
 * How long a seat is planned for. Work is planned in these steps and no
 * others (Luke, 2026-09-17), so the box is a pick rather than a number. Ten
 * minutes is stored as 0.17 hours, which is what two decimal places allow.
 */
export const HOUR_OPTIONS: Array< { value: string; label: string } > = [
  { value: '0.17', label: '10 min' },
  { value: '0.5', label: '30 min' },
  { value: '0.75', label: '45 min' },
  { value: '1', label: '1 hour' },
  { value: '1.5', label: '1.5 hours' },
  { value: '2', label: '2 hours' },
  { value: '2.5', label: '2.5 hours' },
  { value: '3', label: '3 hours' },
];

/** The list, with whatever is already stored kept on it so an older figure is shown rather than silently dropped. */
export function hourOptions( current: string ): Array< { value: string; label: string } > {
  const options = [ { value: '', label: 'Not set' }, ...HOUR_OPTIONS ];

  if ( '' !== current && ! options.some( ( one ) => one.value === current ) ) {
    options.push( { value: current, label: `${ current } hours (as recorded)` } );
  }

  return options;
}

export function HoursSelect( {
  id,
  value,
  onChange,
  testId,
  label,
  className,
}: {
  id?: string;
  value: string;
  onChange: ( value: string ) => void;
  testId?: string;
  label?: string;
  className?: string;
} ) {
  return <Select id={ id } className={ className } data-testid={ testId } aria-label={ label } value={ value } options={ hourOptions( value ) } onChange={ ( event ) => onChange( event.target.value ) } />;
}
