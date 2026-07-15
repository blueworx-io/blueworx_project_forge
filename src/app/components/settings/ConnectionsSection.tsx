import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Plus, X, Check, AlertCircle, Loader2, Send, Trash2 } from 'lucide-react';
import { Connection, ConnectionDelivery, ItemType } from '../../types';
import {
  fetchConnections, createConnection, updateConnection,
  deleteConnection, testConnection, fetchConnectionLog,
} from '../../api/wordpress';
import { Card, inputStyle } from './shared';

const ITEM_TYPES: ItemType[] = [ 'feature', 'subitem', 'bug', 'feedback', 'release' ];

const TYPE_LABEL: Record<ItemType, string> = {
  feature:  'Feature',
  subitem:  'Sub-item',
  bug:      'Bug',
  feedback: 'Feedback',
  release:  'Release',
};

interface FormState {
  id?: string;
  name: string;
  url: string;
  authToken: string;
  itemTypes: ItemType[];
  enabled: boolean;
  hasToken: boolean;
}

const EMPTY_FORM: FormState = {
  name: '', url: '', authToken: '', itemTypes: [], enabled: true, hasToken: false,
};

export default function ConnectionsSection() {
  const [ connections, setConnections ] = useState<Connection[]>( [] );
  const [ log, setLog ]                 = useState<ConnectionDelivery[]>( [] );
  const [ loading, setLoading ]         = useState( true );
  const [ form, setForm ]               = useState<FormState | null>( null );
  const [ saving, setSaving ]           = useState( false );
  const [ error, setError ]             = useState<string | null>( null );
  const [ testing, setTesting ]         = useState<Set<string>>( new Set() );
  const [ testResult, setTestResult ]   = useState<Record<string, string>>( {} );

  // Only the very first load shows the full-panel spinner; later refreshes
  // (after save/remove/test) update the data in place.
  const initialLoadDone = useRef( false );

  const load = useCallback( async () => {
    if ( ! initialLoadDone.current ) setLoading( true );
    setError( null );
    try {
      const [ conns, entries ] = await Promise.all( [ fetchConnections(), fetchConnectionLog() ] );
      setConnections( conns );
      setLog( entries );
    } catch ( e ) {
      setError( e instanceof Error ? e.message : 'Failed to load connections' );
    }
    if ( ! initialLoadDone.current ) {
      setLoading( false );
      initialLoadDone.current = true;
    }
  }, [] );

  useEffect( () => { void load(); }, [ load ] );

  const save = async () => {
    if ( ! form ) return;
    setSaving( true );
    setError( null );
    try {
      // Omit authToken entirely when blank so the server leaves it unchanged.
      const payload: Partial<Connection> = {
        name: form.name,
        url: form.url,
        itemTypes: form.itemTypes,
        enabled: form.enabled,
      };
      if ( form.authToken ) payload.authToken = form.authToken;

      if ( form.id ) await updateConnection( form.id, payload );
      else           await createConnection( payload );

      setForm( null );
      await load();
    } catch ( e ) {
      setError( e instanceof Error ? e.message : 'Save failed' );
    }
    setSaving( false );
  };

  const remove = async ( id: string ) => {
    if ( ! window.confirm( 'Delete this connection? Items will stop being pushed to it.' ) ) return;
    setError( null );
    try {
      await deleteConnection( id );
      await load();
    } catch ( e ) {
      setError( e instanceof Error ? e.message : 'Delete failed' );
    }
  };

  const runTest = async ( id: string ) => {
    setError( null );
    setTesting( prev => new Set( prev ).add( id ) );
    try {
      const r = await testConnection( id );
      setTestResult( prev => ( {
        ...prev,
        [ id ]: r.success ? `Success (${ r.httpCode })` : `Failed: ${ r.error || r.httpCode }`,
      } ) );
    } catch ( e ) {
      setTestResult( prev => ( { ...prev, [ id ]: e instanceof Error ? e.message : 'Test failed' } ) );
    }
    setTesting( prev => {
      const next = new Set( prev );
      next.delete( id );
      return next;
    } );
    void load();
  };

  const toggleType = ( t: ItemType ) => {
    if ( ! form ) return;
    setForm( {
      ...form,
      itemTypes: form.itemTypes.includes( t )
        ? form.itemTypes.filter( x => x !== t )
        : [ ...form.itemTypes, t ],
    } );
  };

  if ( loading ) {
    return <Card><Loader2 size={16} className="animate-spin" /> Loading connections…</Card>;
  }

  return (
    <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
      <Card>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:4 }}>
          <h3 style={{ margin:0, fontSize:15, color:'#1a1f36' }}>Connections</h3>
          { ! form && (
            <button onClick={ () => setForm( EMPTY_FORM ) }
              style={{ display:'inline-flex',alignItems:'center',gap:6,padding:'6px 12px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:13,cursor:'pointer' }}>
              <Plus size={14} /> Add connection
            </button>
          ) }
        </div>
        <p style={{ margin:'0 0 16px', fontSize:13, color:'#64748b' }}>
          When a new item is created, Forge sends its full details — including the description — to each
          enabled connection below.
        </p>

        { error && (
          <div style={{ display:'flex',alignItems:'center',gap:6,padding:'8px 10px',marginBottom:12,borderRadius:6,background:'#fff1f2',color:'#e11d48',fontSize:13 }}>
            <AlertCircle size={14} /> { error }
          </div>
        ) }

        { connections.length === 0 && ! form && (
          <p style={{ margin:0, fontSize:13, color:'#94a3b8' }}>No connections yet.</p>
        ) }

        { connections.map( c => (
          <div key={ c.id } style={{ display:'flex',alignItems:'center',gap:12,padding:'10px 0',borderTop:'1px solid #f1f5f9' }}>
            <div style={{ flex:1, minWidth:0 }}>
              <div style={{ fontSize:14, color:'#1a1f36' }}>
                { c.name }
                { ! c.enabled && <span style={{ marginLeft:8,fontSize:11,color:'#94a3b8' }}>Disabled</span> }
              </div>
              <div style={{ fontSize:12, color:'#64748b', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                { c.url }
              </div>
              <div style={{ display:'flex', gap:4, marginTop:4, flexWrap:'wrap' }}>
                { c.itemTypes.map( t => (
                  <span key={ t } style={{ fontSize:11,padding:'1px 6px',borderRadius:4,background:'#f1f5f9',color:'#475569' }}>
                    { TYPE_LABEL[ t ] }
                  </span>
                ) ) }
              </div>
              { testResult[ c.id ] && (
                <div style={{ fontSize:12, marginTop:4, color: testResult[ c.id ].startsWith( 'Success' ) ? '#16a34a' : '#e11d48' }}>
                  { testResult[ c.id ] }
                </div>
              ) }
            </div>
            <button onClick={ () => void runTest( c.id ) } disabled={ testing.has( c.id ) }
              style={{ display:'inline-flex',alignItems:'center',gap:4,padding:'5px 10px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:12,cursor:'pointer' }}>
              { testing.has( c.id ) ? <Loader2 size={12} /> : <Send size={12} /> } Test
            </button>
            <button onClick={ () => setForm( {
              id: c.id, name: c.name, url: c.url, authToken: '',
              itemTypes: c.itemTypes, enabled: c.enabled, hasToken: !! c.authTokenHint,
            } ) }
              style={{ padding:'5px 10px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:12,cursor:'pointer' }}>
              Edit
            </button>
            <button onClick={ () => void remove( c.id ) } aria-label="Delete connection"
              style={{ padding:5,borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',cursor:'pointer',color:'#e11d48' }}>
              <Trash2 size={12} />
            </button>
          </div>
        ) ) }
      </Card>

      { form && (
        <Card>
          <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:12 }}>
            <h3 style={{ margin:0, fontSize:15, color:'#1a1f36' }}>{ form.id ? 'Edit' : 'New' } connection</h3>
            <button onClick={ () => setForm( null ) } aria-label="Close"
              style={{ border:'none', background:'none', cursor:'pointer', color:'#64748b' }}>
              <X size={16} />
            </button>
          </div>

          <div style={{ display:'flex', flexDirection:'column', gap:12 }}>
            <label style={{ fontSize:13, color:'#475569' }}>
              Name
              <input style={ inputStyle } value={ form.name }
                onChange={ e => setForm( { ...form, name: e.target.value } ) }
                placeholder="Foundry" />
            </label>

            <label style={{ fontSize:13, color:'#475569' }}>
              Endpoint URL
              <input style={ inputStyle } value={ form.url }
                onChange={ e => setForm( { ...form, url: e.target.value } ) }
                placeholder="https://example.com/api/ingest" />
              <span style={{ fontSize:12, color:'#94a3b8' }}>Must start with https://</span>
            </label>

            <label style={{ fontSize:13, color:'#475569' }}>
              Bearer token
              <input style={ inputStyle } type="password" value={ form.authToken }
                onChange={ e => setForm( { ...form, authToken: e.target.value } ) }
                placeholder={ form.hasToken ? 'Saved — leave blank to keep' : 'Optional' } />
            </label>

            <div style={{ fontSize:13, color:'#475569' }}>
              Push on creation of
              <div style={{ display:'flex', gap:12, flexWrap:'wrap', marginTop:6 }}>
                { ITEM_TYPES.map( t => (
                  <label key={ t } style={{ display:'inline-flex', alignItems:'center', gap:5, fontSize:13 }}>
                    <input type="checkbox" checked={ form.itemTypes.includes( t ) }
                      onChange={ () => toggleType( t ) } />
                    { TYPE_LABEL[ t ] }
                  </label>
                ) ) }
              </div>
            </div>

            <label style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:13, color:'#475569' }}>
              <input type="checkbox" checked={ form.enabled }
                onChange={ e => setForm( { ...form, enabled: e.target.checked } ) } />
              Enabled
            </label>

            <div style={{ display:'flex', gap:8 }}>
              <button onClick={ () => void save() } disabled={ saving }
                style={{ display:'inline-flex',alignItems:'center',gap:6,padding:'7px 14px',borderRadius:6,border:'none',background:'#1a1f36',color:'#fff',fontSize:13,cursor:'pointer' }}>
                { saving ? <Loader2 size={13} /> : <Check size={13} /> } Save
              </button>
              <button onClick={ () => setForm( null ) }
                style={{ padding:'7px 14px',borderRadius:6,border:'1px solid #e2e8f0',background:'#fff',fontSize:13,cursor:'pointer' }}>
                Cancel
              </button>
            </div>
          </div>
        </Card>
      ) }

      <Card>
        <h3 style={{ margin:'0 0 12px', fontSize:15, color:'#1a1f36' }}>Activity</h3>
        { log.length === 0 && (
          <p style={{ margin:0, fontSize:13, color:'#94a3b8' }}>No deliveries yet.</p>
        ) }
        { log.map( d => {
          const conn = connections.find( c => c.id === d.connectionId );
          const color = d.status === 'success' ? '#16a34a' : d.status === 'retrying' ? '#d97706' : '#e11d48';
          return (
            <div key={ d.id } style={{ display:'flex',alignItems:'center',gap:10,padding:'7px 0',borderTop:'1px solid #f1f5f9',fontSize:13 }}>
              <span style={{ color, fontSize:11, textTransform:'uppercase', width:64 }}>{ d.status }</span>
              <span style={{ flex:1, color:'#475569' }}>
                { conn?.name ?? 'Deleted connection' } · { d.itemType } #{ d.itemId }
                { d.attempt > 1 && <span style={{ color:'#94a3b8' }}> · attempt { d.attempt }</span> }
              </span>
              <span style={{ color:'#94a3b8', fontSize:12 }}>
                { d.error ? d.error : d.httpCode }
              </span>
            </div>
          );
        } ) }
      </Card>
    </div>
  );
}
