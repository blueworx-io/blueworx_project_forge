import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import '../../tokens/forge.css';
import '../kit/kit.css';
import './client.css';

const container = document.getElementById( 'bwx-forge-client-app' );

if ( container ) {
  createRoot( container ).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
