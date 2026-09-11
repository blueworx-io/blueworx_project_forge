import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { Gallery } from './kit/Gallery';
import './styles.css';
import './kit/kit.css';

const container = document.getElementById( 'bwx-forge-app' );

if ( container ) {
  // `#kit` shows the component kit instead of the app (#296): every piece on
  // one page, for eyes and for the checks. It reads nothing from the server.
  const gallery = '#kit' === window.location.hash;

  createRoot( container ).render(
    <StrictMode>{ gallery ? <Gallery /> : <App /> }</StrictMode>
  );
}
