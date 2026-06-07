import { create } from 'zustand';
import { Item } from '../types';

type View = 'kanban' | 'gantt' | 'calendar' | 'settings';

interface UIState {
  currentView:  View;
  prevView:     View;
  selectedItem: Item | null;
  isModalOpen:  boolean;
}

interface UIActions {
  switchView:    ( view: View ) => void;
  openSettings:  () => void;
  closeSettings: () => void;
  openModal:     ( item: Item ) => void;
  closeModal:    () => void;
}

export const useUIStore = create<UIState & UIActions>( set => ( {
  currentView:  'gantt',
  prevView:     'gantt',
  selectedItem: null,
  isModalOpen:  false,

  switchView: ( view ) => {
    set( { currentView: view } );
    window.scrollTo( 0, 0 );
  },

  openSettings: () => set( prev => ( {
    prevView:    prev.currentView !== 'settings' ? prev.currentView : prev.prevView,
    currentView: 'settings',
  } ) ),

  closeSettings: () => set( prev => ( { currentView: prev.prevView } ) ),

  openModal:  ( item ) => set( { selectedItem: item, isModalOpen: true } ),
  closeModal: ()       => set( { isModalOpen: false } ),
} ) );
