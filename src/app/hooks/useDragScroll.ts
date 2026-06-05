import { useEffect, useRef } from 'react';

/**
 * Drag-to-scroll with momentum deceleration.
 * The scroll glides to a natural stop instead of cutting off immediately.
 */
export function useDragScroll<T extends HTMLElement>() {
  const ref = useRef<T>( null );

  useEffect( () => {
    const el = ref.current;
    if ( !el ) return;

    let isDown   = false;
    let startX   = 0;
    let startY   = 0;
    let baseLeft = 0;
    let baseTop  = 0;

    // Velocity tracking
    let velX     = 0;
    let velY     = 0;
    let lastX    = 0;
    let lastY    = 0;
    let lastTime = 0;
    let rafId: number | null = null;

    const FRICTION = 0.88; // lower = stops faster, higher = glides longer
    const MIN_VEL  = 0.3;  // px/frame below which we stop

    const stopMomentum = () => {
      if ( rafId !== null ) { cancelAnimationFrame( rafId ); rafId = null; }
    };

    const momentum = () => {
      if ( Math.abs( velX ) < MIN_VEL && Math.abs( velY ) < MIN_VEL ) {
        stopMomentum();
        return;
      }
      el.scrollLeft += velX;
      el.scrollTop  += velY;
      velX *= FRICTION;
      velY *= FRICTION;
      rafId = requestAnimationFrame( momentum );
    };

    const onMouseDown = ( e: MouseEvent ) => {
      const target = e.target as HTMLElement;
      if (
        target.tagName === 'BUTTON' || target.tagName === 'A' ||
        target.closest( 'button' ) || target.closest( 'a' ) ||
        target.draggable
      ) return;

      stopMomentum();
      isDown   = true;
      startX   = e.pageX - el.offsetLeft;
      startY   = e.pageY - el.offsetTop;
      baseLeft = el.scrollLeft;
      baseTop  = el.scrollTop;
      lastX    = e.pageX;
      lastY    = e.pageY;
      lastTime = performance.now();
      velX     = 0;
      velY     = 0;
      el.style.cursor     = 'grabbing';
      el.style.userSelect = 'none';
    };

    const onMouseMove = ( e: MouseEvent ) => {
      if ( !isDown ) return;
      e.preventDefault();

      const now = performance.now();
      const dt  = now - lastTime;
      if ( dt > 0 ) {
        // Capture instantaneous velocity (px/ms) and scale to ~60fps frame
        velX = ( e.pageX - lastX ) / dt * 16;
        velY = ( e.pageY - lastY ) / dt * 16;
      }
      lastX    = e.pageX;
      lastY    = e.pageY;
      lastTime = now;

      el.scrollLeft = baseLeft - ( e.pageX - el.offsetLeft - startX );
      el.scrollTop  = baseTop  - ( e.pageY - el.offsetTop  - startY );
    };

    const onRelease = () => {
      if ( !isDown ) return;
      isDown = false;
      el.style.cursor     = 'grab';
      el.style.userSelect = '';
      // Reverse direction: drag left = scroll right, so negate velocity
      velX = -velX;
      velY = -velY;
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
  }, [] );

  return ref;
}
