import { create } from 'zustand';
import { Item } from '../types';
import { ViewFilters, EMPTY_FILTERS, FilterKey } from '../utils/filters';
import { View, parseUrlState } from '../utils/urlState';

interface UIState {
  currentView:     View;
  prevView:        View;
  selectedItem:    Item | null;
  isModalOpen:     boolean;
  filters:         ViewFilters;
  filterPanelOpen: boolean;
}

interface UIActions {
  switchView:        ( view: View ) => void;
  openSettings:      () => void;
  closeSettings:     () => void;
  openModal:         ( item: Item ) => void;
  closeModal:        () => void;
  setFilter:         ( key: FilterKey, value: string ) => void;
  resetFilters:      () => void;
  toggleFilterPanel: () => void;
  setFilterPanelOpen: ( open: boolean ) => void;
}

// Restore view + filters from a shared deep link on load (#25, #35).
// Settings is allowed here; App redirects non-admins away from it (#25 follow-up).
const initial = parseUrlState();

export const useUIStore = create<UIState & UIActions>( set => ( {
  currentView:  initial.view ?? 'gantt',
  prevView:     'gantt',
  selectedItem: null,
  isModalOpen:  false,
  filters:      initial.filters ?? EMPTY_FILTERS,
  filterPanelOpen: false,

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

  setFilter:    ( key, value ) => set( prev => ( { filters: { ...prev.filters, [key]: value } } ) ),
  resetFilters: ()            => set( { filters: { ...EMPTY_FILTERS } } ),

  toggleFilterPanel:  ()       => set( prev => ( { filterPanelOpen: ! prev.filterPanelOpen } ) ),
  setFilterPanelOpen: ( open ) => set( { filterPanelOpen: open } ),
} ) );
