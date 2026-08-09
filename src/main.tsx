import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import App from './App.tsx';
import DebugPage from './pages/DebugPage.tsx';
import ErrorBoundary from './components/ErrorBoundary.tsx';
import ToasterHost from './components/ToasterHost.tsx';
import './index.css';

/** Apex (zinesh.com) API POST'ları CF 301'de path kaybediyor — www'ye zorla. */
if (typeof window !== 'undefined' && window.location.hostname === 'zinesh.com') {
  const target = `https://www.zinesh.com${window.location.pathname}${window.location.search}${window.location.hash}`;
  window.location.replace(target);
} else if (typeof window !== 'undefined' && /^\/debug\/?$/.test(window.location.pathname)) {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <ErrorBoundary label="debug">
        <DebugPage />
      </ErrorBoundary>
      <ToasterHost />
    </StrictMode>,
  );
} else {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <ErrorBoundary label="root">
        <App />
      </ErrorBoundary>
      <ToasterHost />
    </StrictMode>,
  );
}
