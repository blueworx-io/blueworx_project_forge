import { useEffect, useRef } from 'react';

interface DragScrollOptions {
  /** Constrain scrolling to a single axis. 'both' detects primary direction on first move. */
  axis?: 'both' | 'x' | 'y';
  /** Allow drag-scroll to start on button/anchor elements (e.g. tab strips where all items are buttons). */
  allowButtons?: boolean;
  /** Multiplier between pointer travel and scroll distance. >1 means a shorter drag scrolls further. */
  gain?: number;
}

export function useDragScroll<T extends HTMLElement>( options: DragScrollOptions = {} ) {
  const { axis = 'both', allowButtons = false, gain = 1 } = options;
  const ref = useRef<T>( null );

  useEffect( () => {
    const el = ref.current;
    if ( !el ) return;

    let isDown      = false;
    let moved       = false;
    let startX      = 0;
    let startY      = 0;
    let baseLeft    = 0;
    let baseTop     = 0;
    let lockedAxis: 'x' | 'y' | null = null;

    let velX     = 0;
    let velY     = 0;
    let lastX    = 0;
    let lastY    = 0;
    let lastTime = 0;
    let rafId: number | null = null;

    const FRICTION       = 0.84;  // lower = momentum decays faster (less coasting)
    const MIN_VEL        = 0.3;
    const MOVE_THRESHOLD = 5;   // px before scroll activates (prevents click jitter)
    const AXIS_THRESHOLD = 5;   // px to determine primary axis in 'both' mode
    const MOMENTUM_SCALE = 0.5;  // dampens fling speed when the drag is released
    const MAX_VEL        = 28;   // caps a single frame's coast distance (px)

    const stopMomentum = () => {
      if ( rafId !== null ) { cancelAnimationFrame( rafId ); rafId = null; }
    };

    const momentum = () => {
      const ax = axis === 'both' ? ( lockedAxis ?? 'x' ) : axis;
      if ( Math.abs( velX ) < MIN_VEL && Math.abs( velY ) < MIN_VEL ) {
        stopMomentum();
        return;
      }
      if ( ax !== 'y' ) el.scrollLeft += velX;
      if ( ax !== 'x' ) el.scrollTop  += velY;
      velX *= FRICTION;
      velY *= FRICTION;
      rafId = requestAnimationFrame( momentum );
    };

    const onMouseDown = ( e: MouseEvent ) => {
      const target = e.target as HTMLElement;
      // Skip if clicking interactive elements or react-dnd drag sources
      if ( !allowButtons && (
        target.tagName === 'BUTTON' || target.tagName === 'A' ||
        target.closest( 'button' ) || target.closest( 'a' ) ||
        target.closest( '[draggable="true"]' )
      ) ) return;

      stopMomentum();
      isDown     = true;
      moved      = false;
      lockedAxis = axis === 'both' ? null : ( axis as 'x' | 'y' );
      startX     = e.pageX - el.offsetLeft;
      startY     = e.pageY - el.offsetTop;
      baseLeft   = el.scrollLeft;
      baseTop    = el.scrollTop;
      lastX      = e.pageX;
      lastY      = e.pageY;
      lastTime   = performance.now();
      velX       = 0;
      velY       = 0;
      el.style.userSelect = 'none';
    };

    const onMouseMove = ( e: MouseEvent ) => {
      if ( !isDown ) return;

      const dx = e.pageX - el.offsetLeft - startX;
      const dy = e.pageY - el.offsetTop  - startY;

      // Require minimum movement before activating — prevents accidental scroll on click
      if ( !moved ) {
        if ( Math.abs( dx ) < MOVE_THRESHOLD && Math.abs( dy ) < MOVE_THRESHOLD ) return;
        moved = true;
      }

      // Lock to primary axis in 'both' mode
      if ( axis === 'both' && lockedAxis === null ) {
        if ( Math.abs( dx ) >= AXIS_THRESHOLD || Math.abs( dy ) >= AXIS_THRESHOLD ) {
          lockedAxis = Math.abs( dx ) >= Math.abs( dy ) ? 'x' : 'y';
        } else {
          return; // not enough movement to determine axis yet
        }
      }

      e.preventDefault();

      const now = performance.now();
      const dt  = now - lastTime;
      if ( dt > 0 ) {
        velX = ( e.pageX - lastX ) / dt * 16 * gain;
        velY = ( e.pageY - lastY ) / dt * 16 * gain;
      }
      lastX    = e.pageX;
      lastY    = e.pageY;
      lastTime = now;

      const ax = axis === 'both' ? ( lockedAxis ?? 'x' ) : axis;
      if ( ax !== 'y' ) el.scrollLeft = baseLeft - dx * gain;
      if ( ax !== 'x' ) el.scrollTop  = baseTop  - dy * gain;
      el.style.cursor = 'grabbing';
    };

    const onRelease = () => {
      if ( !isDown ) return;
      isDown = false;
      el.style.cursor     = 'grab';
      el.style.userSelect = '';
      // Invert (drag direction → scroll direction), dampen, and cap the fling speed
      const clamp = ( v: number ) => Math.max( -MAX_VEL, Math.min( MAX_VEL, v ) );
      velX = clamp( -velX * MOMENTUM_SCALE );
      velY = clamp( -velY * MOMENTUM_SCALE );
      if ( Math.abs( velX ) > MIN_VEL || Math.abs( velY ) > MIN_VEL ) {
        rafId = requestAnimationFrame( momentum );
      }
    };

    el.addEventListener( 'mousedown', onMouseDown );
    document.addEventListener( 'mousemove', onMouseMove );
    document.addEventListener( 'mouseup', onRelease );
    el.addEventListener( 'mouseleave', onRelease );

    return () => {
      stopMomentum();
      el.removeEventListener( 'mousedown', onMouseDown );
      document.removeEventListener( 'mousemove', onMouseMove );
      document.removeEventListener( 'mouseup', onRelease );
      el.removeEventListener( 'mouseleave', onRelease );
    };
  }, [axis, allowButtons, gain] );

  return ref;
}
