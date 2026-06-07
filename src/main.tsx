import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/index.css';
import App from './app/App';
import { initErrorLogger } from './app/api/errorLogger';

// Start capturing JS / promise / network errors before the app mounts.
initErrorLogger();

const root = document.getElementById( 'forge-pm-app' );
if ( root ) {
  createRoot( root ).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
