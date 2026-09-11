import { createContext, useCallback, useContext, useEffect, useId, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { X } from 'lucide-react';

/*
 * The two transient layers: a dialog over a scrim, and the toasts in the
 * corner. Both are announced, both close from the keyboard, and neither
 * animates in — they appear.
 */

export function Modal( {
  title,
  description,
  width = 480,
  children,
  footer,
  onClose,
}: {
  title: ReactNode;
  description?: ReactNode;
  width?: number;
  children?: ReactNode;
  footer?: ReactNode;
  onClose: () => void;
} ) {
  const titleId = useId();
  const descriptionId = useId();
  const closeRef = useRef< HTMLButtonElement >( null );
  const dialogRef = useRef< HTMLDivElement >( null );

  // Focus lands inside the dialog on open and goes back to where it was on
  // close, so a keyboard user is never left on a control the scrim hides.
  useEffect( () => {
    const before = document.activeElement as HTMLElement | null;
    closeRef.current?.focus();
    return () => before?.focus?.();
  }, [] );

  useEffect( () => {
    const onKey = ( event: KeyboardEvent ) => {
      if ( 'Escape' === event.key ) {
        event.stopPropagation();
        onClose();
        return;
      }
      if ( 'Tab' !== event.key || ! dialogRef.current ) return;

      const focusable = dialogRef.current.querySelectorAll< HTMLElement >(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
      if ( 0 === focusable.length ) return;
      const first = focusable[ 0 ];
      const last = focusable[ focusable.length - 1 ];

      if ( event.shiftKey && document.activeElement === first ) {
        event.preventDefault();
        last.focus();
      } else if ( ! event.shiftKey && document.activeElement === last ) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener( 'keydown', onKey );
    return () => document.removeEventListener( 'keydown', onKey );
  }, [ onClose ] );

  return (
    <div className="fk-scrim" onClick={ onClose }>
      <div
        ref={ dialogRef }
        role="dialog"
        aria-modal="true"
        aria-labelledby={ titleId }
        aria-describedby={ description ? descriptionId : undefined }
        className="fk-dialog"
        style={ { width } }
        onClick={ ( event ) => event.stopPropagation() }
      >
        <div className="fk-dialog-head">
          <div>
            <h2 id={ titleId } className="fk-dialog-title">
              { title }
            </h2>
            { description && (
              <p id={ descriptionId } className="fk-dialog-description">
                { description }
              </p>
            ) }
          </div>
          <button ref={ closeRef } type="button" className="fk-icon-btn" aria-label="Close" onClick={ onClose }>
            <X size={ 18 } strokeWidth={ 1.5 } aria-hidden="true" />
          </button>
        </div>
        { children && <div className="fk-dialog-body">{ children }</div> }
        { footer && <div className="fk-dialog-foot">{ footer }</div> }
      </div>
    </div>
  );
}

export type ToastTone = 'ok' | 'warn' | 'danger';

interface Toast {
  id: number;
  message: string;
  tone: ToastTone;
  stamp?: string;
}

const ToastContext = createContext< ( message: string, tone?: ToastTone, stamp?: string ) => void >( () => {} );

/** Wrap an app in this once; then any screen can `useToast()`. */
export function ToastProvider( { children, stamp }: { children: ReactNode; stamp?: () => string } ) {
  const [ toasts, setToasts ] = useState< Toast[] >( [] );
  const next = useRef( 1 );

  const dismiss = useCallback( ( id: number ) => setToasts( ( list ) => list.filter( ( t ) => t.id !== id ) ), [] );

  const show = useCallback(
    ( message: string, tone: ToastTone = 'ok', at?: string ) => {
      const id = next.current++;
      setToasts( ( list ) => list.concat( { id, message, tone, stamp: at ?? stamp?.() } ) );
      setTimeout( () => dismiss( id ), 7000 );
    },
    [ dismiss, stamp ]
  );

  const value = useMemo( () => show, [ show ] );

  return (
    <ToastContext.Provider value={ value }>
      { children }
      <div className="fk-toasts">
        { toasts.map( ( toast ) => (
          <div key={ toast.id } className="fk-toast" role="status" data-tone={ toast.tone }>
            <span className="fk-dot" aria-hidden="true" />
            <span className="fk-toast-text">
              { toast.message }
              { toast.stamp && <span className="fk-toast-stamp">{ toast.stamp }</span> }
            </span>
            <button type="button" className="fk-icon-btn" aria-label="Dismiss" onClick={ () => dismiss( toast.id ) }>
              <X size={ 16 } strokeWidth={ 1.5 } aria-hidden="true" />
            </button>
          </div>
        ) ) }
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  return useContext( ToastContext );
}
