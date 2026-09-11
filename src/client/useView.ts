import { useCallback, useEffect, useState } from 'react';
import { ApiError } from './api';

/**
 * One read, with the three states a screen has to draw: still loading, could
 * not be read, and read. `reload( true )` asks the studio again rather than
 * taking the cached copy.
 */
export function useView< T >( fetcher: ( refresh: boolean ) => Promise< T > ) {
  const [ view, setView ] = useState< T | null >( null );
  const [ error, setError ] = useState< string | null >( null );
  const [ loading, setLoading ] = useState( true );

  const reload = useCallback(
    async ( refresh = false ) => {
      setLoading( true );
      try {
        setView( await fetcher( refresh ) );
        setError( null );
      } catch ( caught ) {
        setError( caught instanceof ApiError ? caught.message : 'Something went wrong reading this.' );
      } finally {
        setLoading( false );
      }
    },
    [ fetcher ]
  );

  useEffect( () => {
    // The read is the effect: it sets state when the answer arrives, which
    // is the one thing this hook is for. The rule is right in general and
    // wrong here, the same as the studio's screens.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void reload();
  }, [ reload ] );

  return { view, error, loading, reload };
}
