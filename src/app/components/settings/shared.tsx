import React from 'react';

export function Card( { children, style }: { children: React.ReactNode; style?: React.CSSProperties } ) {
  return <div style={{ background:'#fff',border:'1px solid #e2e8f0',borderRadius:8,padding:20,...style }}>{ children }</div>;
}

export const inputStyle: React.CSSProperties = {
  width:'100%', padding:'7px 10px', borderRadius:6,
  border:'1px solid #e2e8f0', fontSize:14, color:'#1a1f36',
  outline:'none', background:'#fff', boxSizing:'border-box',
};
