import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import TeknikDokumanPage from './pages/TeknikDokumanPage';
import './index.css';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <TeknikDokumanPage />
  </StrictMode>,
);
