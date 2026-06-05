import { useState } from 'react';
import { ChevronLeft, ChevronRight, Calendar as CalendarIcon, Target, Plus, X } from 'lucide-react';
import { format, addMonths, subMonths, startOfMonth, endOfMonth, eachDayOfInterval, isSameMonth, isSameDay, startOfWeek, endOfWeek, parseISO, isWithinInterval, startOfDay, endOfDay } from 'date-fns';
import { Item } from '../types';
import { AppData } from '../App';
import { createCompanyDate } from '../api/wordpress';

// Design tokens
const C = {
  bg: '#fafbfc', white: '#ffffff', border: '#e2e8f0',
  fg: '#1a1f36', mutedFg: '#64748b', muted: '#f1f5f9',
  primary: '#2563eb', primaryFg: '#ffffff',
};

// Explicit button style helpers
const btnPrimary: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: C.primary, color: C.primaryFg, border: 'none', borderRadius: 6, cursor: 'pointer', boxShadow: '0 1px 3px rgba(37,99,235,0.35)' };
const btnSecondary: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: C.white, color: C.fg, border: `1px solid ${ C.border }`, borderRadius: 6, cursor: 'pointer', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' };
const btnIcon: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 7, backgroundColor: C.white, color: C.mutedFg, border: `1px solid ${ C.border }`, cursor: 'pointer' };

interface CalendarViewProps {
  data: AppData;
  onItemClick: ( item: Item ) => void;
  isAdmin: boolean;
  onDataChange: () => void;
}

export function CalendarView( { data, onItemClick, isAdmin, onDataChange }: CalendarViewProps ) {
  const [currentDate, setCurrentDate] = useState( new Date( 2026, 5, 1 ) );
  const [isAddingEvent, setIsAddingEvent] = useState( false );
  const [isSaving, setIsSaving] = useState( false );
  const [newEvent, setNewEvent] = useState( { title: '', date: format( new Date(), 'yyyy-MM-dd' ), description: '', tracked: false } );

  const monthStart = startOfMonth( currentDate );
  const monthEnd   = endOfMonth( monthStart );
  const days       = eachDayOfInterval( { start: startOfWeek( monthStart ), end: endOfWeek( monthEnd ) } );

  const handleAddEvent = async ( e: React.FormEvent ) => {
    e.preventDefault();
    if ( !newEvent.title || !newEvent.date ) return;
    setIsSaving( true );
    try {
      if ( window.forgePMData ) {
        await createCompanyDate( newEvent );
        onDataChange();
      } else {
        data.companyDates.push( { id: `cd-${ Date.now() }`, ...newEvent } );
      }
    } finally {
      setIsSaving( false );
      setIsAddingEvent( false );
      setNewEvent( { title: '', date: format( new Date(), 'yyyy-MM-dd' ), description: '', tracked: false } );
    }
  };

  const getEventsForDay = ( day: Date ) => {
    // Releases come FIRST — ensures continuous bars stay at the same vertical position across all cells in a week row
    const dayReleases = data.releases.filter( release =>
      isWithinInterval( day, { start: startOfDay( parseISO( release.startWeek ) ), end: endOfDay( parseISO( release.endWeek ) ) } )
    );
    const dayCompanyDates = data.companyDates.filter( cd => isSameDay( parseISO( cd.date ), day ) );
    return { dayReleases, dayCompanyDates };
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', backgroundColor: C.bg }}>

      {/* ── Calendar header — sticky below the app header (57px) ─── */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 24px', borderBottom: `1px solid ${ C.border }`, backgroundColor: C.white, position: 'sticky', top: 57, zIndex: 40 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ padding: 8, backgroundColor: '#dbeafe', borderRadius: 8, color: C.primary, display: 'flex' }}>
            <CalendarIcon size={ 20 } />
          </div>
          <h2 style={{ fontSize: 20, fontWeight: 700, color: C.fg, margin: 0 }}>{ format( currentDate, 'MMMM yyyy' ) }</h2>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          { isAdmin && (
            <button onClick={ () => setIsAddingEvent( true ) } style={ btnPrimary }>
              <Plus size={ 16 } /><span>Add Date</span>
            </button>
          ) }
          <div style={{ width: 1, height: 24, backgroundColor: C.border }} />
          <button onClick={ () => setCurrentDate( new Date( 2026, 5, 1 ) ) } style={ btnSecondary }>Today</button>
          <div style={{ display: 'flex', border: `1px solid ${ C.border }`, borderRadius: 6, overflow: 'hidden', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }}>
            <button onClick={ () => setCurrentDate( subMonths( currentDate, 1 ) ) } style={{ ...btnIcon, borderRight: `1px solid ${ C.border }` }}>
              <ChevronLeft size={ 16 } />
            </button>
            <button onClick={ () => setCurrentDate( addMonths( currentDate, 1 ) ) } style={ btnIcon }>
              <ChevronRight size={ 16 } />
            </button>
          </div>
        </div>
      </div>

      {/* ── Calendar grid — no internal scroll, browser handles it ─── */}
      <div style={{ display: 'flex', flexDirection: 'column', backgroundColor: '#f1f5f9' }}>

        {/* Day-of-week header — sticks below the app header (57px) + calendar month header (~57px) */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', borderBottom: `1px solid ${ C.border }`, backgroundColor: C.white, position: 'sticky', top: 114, zIndex: 10 }}>
          { ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map( d => (
            <div key={ d } style={{ padding: '10px 0', textAlign: 'center', fontSize: 13, fontWeight: 600, color: C.mutedFg }}>{ d }</div>
          ) ) }
        </div>

        {/* Days grid */}
        <div style={{ flex: 1, display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gridAutoRows: 'minmax(120px, 1fr)' }}>
          { days.map( ( day, idx ) => {
            const isCurrentMonth = isSameMonth( day, monthStart );
            const isToday = isSameDay( day, new Date( 2026, 5, 15 ) );
            const { dayReleases, dayCompanyDates } = getEventsForDay( day );

            return (
              <div key={ day.toString() } style={{
                minHeight: 120, paddingBottom: 8,
                borderBottom: `1px solid ${ C.border }`,
                borderRight: `1px solid ${ C.border }`,
                borderLeft: idx % 7 === 0 ? `1px solid ${ C.border }` : 'none',
                display: 'flex', flexDirection: 'column',
                backgroundColor: isCurrentMonth ? C.white : '#f8fafc',
              }}>
                {/* Day number */}
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '6px 8px 4px' }}>
                  <span style={{
                    fontSize: 13, fontWeight: 500,
                    width: 28, height: 28, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: '50%',
                    backgroundColor: isToday ? C.primary : 'transparent',
                    color: isToday ? '#fff' : isCurrentMonth ? C.fg : C.mutedFg,
                  }}>
                    { format( day, 'd' ) }
                  </span>
                </div>

                {/* Events — releases first, then company dates, ensuring bar continuity */}
                <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 3, overflow: 'hidden' }}>

                  {/* Releases — rendered FIRST so they stay at the same vertical slot across all days in the row */}
                  { dayReleases.map( release => {
                    const isStart = isSameDay( day, parseISO( release.startWeek ) );
                    const isEnd   = isSameDay( day, parseISO( release.endWeek ) );
                    return (
                      <div
                        key={ `rel-${ release.id }` }
                        onClick={ () => onItemClick( release ) }
                        style={{
                          cursor: 'pointer', padding: '3px 8px',
                          fontSize: 11, fontWeight: 500,
                          backgroundColor: '#f0fdf4', color: '#065f46',
                          borderTop: '1px solid #a7f3d0', borderBottom: '1px solid #a7f3d0',
                          borderLeft: isStart ? '1px solid #a7f3d0' : 'none',
                          borderRight: isEnd ? '1px solid #a7f3d0' : 'none',
                          borderRadius: isStart && isEnd ? 4 : isStart ? '4px 0 0 4px' : isEnd ? '0 4px 4px 0' : 0,
                          marginLeft: isStart ? 8 : 0,
                          marginRight: isEnd ? 8 : 0,
                          display: 'flex', alignItems: 'center', gap: 4, minHeight: 20,
                        }}
                      >
                        { isStart && <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: '#10b981', flexShrink: 0 }} /> }
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', opacity: ( !isStart && day.getDay() !== 0 ) ? 0.5 : 1 }}>
                          { release.name }
                        </span>
                      </div>
                    );
                  } ) }

                  {/* Company dates */}
                  { dayCompanyDates.map( cd => (
                    <div key={ `cd-${ cd.id }` } style={{ margin: '0 8px', padding: '4px 8px', fontSize: 11, fontWeight: 600, backgroundColor: '#fef3c7', color: '#92400e', border: '1px solid #fcd34d', borderRadius: 4, boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }} title={ cd.description }>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 4, marginBottom: 1 }}>
                        <Target size={ 10 } />
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ cd.title }</span>
                      </div>
                      { cd.description && <div style={{ fontSize: 10, fontWeight: 400, opacity: 0.75, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ cd.description }</div> }
                    </div>
                  ) ) }
                </div>
              </div>
            );
          } ) }
        </div>
      </div>

      {/* ── Add Date modal ───────────────────────────────────────── */}
      { isAddingEvent && (
        <div style={{ position: 'fixed', inset: 0, zIndex: 50, backgroundColor: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
          <div style={{ backgroundColor: C.white, borderRadius: 12, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: 440, overflow: 'hidden', border: `1px solid ${ C.border }` }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: `1px solid ${ C.border }` }}>
              <h3 style={{ fontSize: 16, fontWeight: 600, color: C.fg, margin: 0 }}>Add Company Date</h3>
              <button onClick={ () => setIsAddingEvent( false ) } style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex' }}><X size={ 20 } /></button>
            </div>
            <form onSubmit={ handleAddEvent } style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Title</label>
                <input autoFocus type="text" required value={ newEvent.title } onChange={ e => setNewEvent( { ...newEvent, title: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', backgroundColor: C.white, boxSizing: 'border-box' }}
                  placeholder="e.g. Q3 Planning Summit" />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Date</label>
                <input type="date" required value={ newEvent.date } onChange={ e => setNewEvent( { ...newEvent, date: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', backgroundColor: C.white, boxSizing: 'border-box' }} />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Description (optional)</label>
                <textarea value={ newEvent.description } onChange={ e => setNewEvent( { ...newEvent, description: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', minHeight: 80, resize: 'vertical', backgroundColor: C.white, boxSizing: 'border-box' }}
                  placeholder="Add details about this event..." />
              </div>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                <input type="checkbox" checked={ newEvent.tracked } onChange={ e => setNewEvent( { ...newEvent, tracked: e.target.checked } ) } style={{ width: 16, height: 16, accentColor: C.primary }} />
                <span style={{ fontSize: 13, fontWeight: 500, color: C.fg }}>Track on Timeline</span>
              </label>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
                <button type="button" onClick={ () => setIsAddingEvent( false ) } style={ btnSecondary }>Cancel</button>
                <button type="submit" disabled={ isSaving } style={{ ...btnPrimary, opacity: isSaving ? 0.6 : 1 }}>
                  { isSaving ? 'Saving...' : 'Save Date' }
                </button>
              </div>
            </form>
          </div>
        </div>
      ) }
    </div>
  );
}
