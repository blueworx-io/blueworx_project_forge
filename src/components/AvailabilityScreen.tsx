/**
 * A person's working week and time off (#136), in the app.
 *
 * The first of the configuration screens to leave WordPress admin. Reads and
 * writes `/users/<id>/availability` and nothing else; the admin page stays
 * beside it until every screen has moved.
 */
export function AvailabilityScreen( { person }: { person: string } ) {
  return (
    <div className="bwx-availability" data-testid="bwx-availability" data-person={ person }>
      { /* Filled in by the next task. */ }
    </div>
  );
}
