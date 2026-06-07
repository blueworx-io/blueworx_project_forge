import { create } from 'zustand';
import { Feature, SubItem, Bug, Feedback, Release, CompanyDate, Item } from '../types';
import {
  sampleFeatures, sampleSubItems, sampleBugs,
  sampleFeedback, sampleReleases, sampleCompanyDates,
} from '../data/sampleData';

interface DataState {
  features:     Feature[];
  subitems:     SubItem[];
  bugs:         Bug[];
  feedback:     Feedback[];
  releases:     Release[];
  companyDates: CompanyDate[];
  isLoading:    boolean;
  refreshKey:   number;
}

interface DataActions {
  setAllData:      ( data: Omit<DataState, 'isLoading' | 'refreshKey'> ) => void;
  patchItem:       ( id: string, patch: Record<string, unknown> ) => void;
  triggerRefresh:  () => void;
  setLoading:      ( v: boolean ) => void;
}

export const useDataStore = create<DataState & DataActions>( set => ( {
  features:     sampleFeatures,
  subitems:     sampleSubItems,
  bugs:         sampleBugs,
  feedback:     sampleFeedback,
  releases:     sampleReleases,
  companyDates: sampleCompanyDates,
  isLoading:    !! window.forgePMData,
  refreshKey:   0,

  setAllData: ( data ) => set( data ),

  patchItem: ( id, patch ) => set( prev => {
    const p = <T extends { id: string }>( arr: T[] ): T[] =>
      arr.map( x => x.id === id ? ( { ...x, ...patch } as T ) : x );
    const isCompanyDate = ! ( 'type' in patch );
    return {
      features:     isCompanyDate ? prev.features     : p( prev.features ),
      subitems:     isCompanyDate ? prev.subitems     : p( prev.subitems ),
      bugs:         isCompanyDate ? prev.bugs         : p( prev.bugs ),
      feedback:     isCompanyDate ? prev.feedback     : p( prev.feedback ),
      releases:     isCompanyDate ? prev.releases     : p( prev.releases ),
      companyDates: isCompanyDate ? p( prev.companyDates as unknown as { id: string }[] ) as unknown as CompanyDate[] : prev.companyDates,
    };
  } ),

  triggerRefresh: () => set( prev => ( { refreshKey: prev.refreshKey + 1 } ) ),

  setLoading: ( v ) => set( { isLoading: v } ),
} ) );

// Selectors — use these with useDataStore(selector) to subscribe to derived data

export const selectAllItems = ( s: DataState ): Item[] =>
  [ ...s.features, ...s.subitems, ...s.bugs, ...s.feedback, ...s.releases ];

export const selectReleaseMap = ( s: DataState ): Map<string, string> =>
  new Map( s.releases.map( r => [ r.id, r.name ] ) );
