import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import App from './App.tsx';
import ErrorBoundary from './components/ErrorBoundary.tsx';
import Toaster from './components/Toaster.tsx';
import './index.css';

/** Apex (zinesh.com) API POST'ları CF 301'de path kaybediyor — www'ye zorla. */
if (typeof window !== 'undefined' && window.location.hostname === 'zinesh.com') {
  const target = `https://www.zinesh.com${window.location.pathname}${window.location.search}${window.location.hash}`;
  window.location.replace(target);
} else {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <ErrorBoundary label="root">
        <App />
      </ErrorBoundary>
      <Toaster />
    </StrictMode>,
  );
}
