// Encode/decode for the "Links up to" picker, which lists both Features and
// Sub-Items in one <select>. Option values are prefixed (`f:` feature, `s:`
// sub-item) so a single control can drive the two mutually-exclusive id fields.

/** The <select> value for the current feature/sub-item link (empty when none). */
export function encodeParentValue( linkedFeatureId?: string, linkedSubItemId?: string ): string {
  if ( linkedSubItemId ) return `s:${ linkedSubItemId }`;
  if ( linkedFeatureId ) return `f:${ linkedFeatureId }`;
  return '';
}

/** Decode a picker value into the two id fields — only ever one is set. */
export function decodeParentValue( value: string ): { linkedFeatureId: string; linkedSubItemId: string } {
  if ( value.startsWith( 's:' ) ) return { linkedFeatureId: '', linkedSubItemId: value.slice( 2 ) };
  if ( value.startsWith( 'f:' ) ) return { linkedFeatureId: value.slice( 2 ), linkedSubItemId: '' };
  return { linkedFeatureId: '', linkedSubItemId: '' };
}
