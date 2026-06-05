import { X, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect } from 'react';

interface ImageLightboxProps {
  images: string[];
  isOpen: boolean;
  currentIndex: number;
  onClose: () => void;
  onNavigate: ( index: number ) => void;
}

export function ImageLightbox( { images, isOpen, currentIndex, onClose, onNavigate }: ImageLightboxProps ) {
  useEffect( () => {
    const handleKeyDown = ( e: KeyboardEvent ) => {
      if ( ! isOpen ) return;
      if ( e.key === 'Escape' ) onClose();
      else if ( e.key === 'ArrowLeft' && currentIndex > 0 ) onNavigate( currentIndex - 1 );
      else if ( e.key === 'ArrowRight' && currentIndex < images.length - 1 ) onNavigate( currentIndex + 1 );
    };
    window.addEventListener( 'keydown', handleKeyDown );
    return () => window.removeEventListener( 'keydown', handleKeyDown );
  }, [isOpen, currentIndex, images.length, onClose, onNavigate] );

  if ( ! isOpen ) return null;

  return (
    <div className="fixed inset-0 z-[60] bg-black/90 flex items-center justify-center">
      <button onClick={ onClose } className="absolute top-4 right-4 p-3 bg-white/10 hover:bg-white/20 rounded-full transition-colors z-10">
        <X className="w-6 h-6 text-white" />
      </button>

      { images.length > 1 && (
        <>
          <button
            onClick={ () => currentIndex > 0 && onNavigate( currentIndex - 1 ) }
            disabled={ currentIndex === 0 }
            className="absolute left-4 p-3 bg-white/10 hover:bg-white/20 rounded-full transition-colors disabled:opacity-30 disabled:cursor-not-allowed z-10"
          >
            <ChevronLeft className="w-6 h-6 text-white" />
          </button>
          <button
            onClick={ () => currentIndex < images.length - 1 && onNavigate( currentIndex + 1 ) }
            disabled={ currentIndex === images.length - 1 }
            className="absolute right-4 p-3 bg-white/10 hover:bg-white/20 rounded-full transition-colors disabled:opacity-30 disabled:cursor-not-allowed z-10"
          >
            <ChevronRight className="w-6 h-6 text-white" />
          </button>
        </>
      ) }

      { images.length > 1 && (
        <div className="absolute bottom-20 sm:bottom-6 left-1/2 -translate-x-1/2 px-4 py-2 bg-white/10 backdrop-blur-sm rounded-full text-white text-sm font-medium z-10">
          { currentIndex + 1 } / { images.length }
        </div>
      ) }

      <div className="max-w-[90vw] max-h-[90vh] p-4">
        <img src={ images[currentIndex] } alt={ `Image ${ currentIndex + 1 }` } className="max-w-full max-h-full object-contain rounded-lg shadow-2xl" />
      </div>

      { images.length > 1 && (
        <div className="absolute bottom-6 left-1/2 -translate-x-1/2 hidden sm:flex items-center gap-2 px-4 py-3 bg-white/10 backdrop-blur-sm rounded-full max-w-[90vw] overflow-x-auto">
          { images.map( ( image, index ) => (
            <button
              key={ index }
              onClick={ () => onNavigate( index ) }
              className={ `w-16 h-16 rounded-lg overflow-hidden border-2 transition-all flex-shrink-0 ${ index === currentIndex ? 'border-white scale-110' : 'border-white/30 hover:border-white/60' }` }
            >
              <img src={ image } alt={ `Thumbnail ${ index + 1 }` } className="w-full h-full object-cover" />
            </button>
          ) ) }
        </div>
      ) }
    </div>
  );
}
