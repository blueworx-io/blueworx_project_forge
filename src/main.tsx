import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/index.css';
import App from './app/App';

const root = document.getElementById( 'forge-pm-app' );
if ( root ) {
  createRoot( root ).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
