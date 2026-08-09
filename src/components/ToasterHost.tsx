import { lazy, Suspense, useEffect, useState } from 'react';
import { getToastsSnapshot, subscribeToasts } from '../lib/toast';

const LazyToaster = lazy(() => import('./Toaster'));

/** motion chunk'ını yalnızca ilk toast tetiklendiğinde yükler. */
export default function ToasterHost() {
  const [mount, setMount] = useState(() => getToastsSnapshot().length > 0);

  useEffect(() => {
    if (mount) return;
    return subscribeToasts(() => {
      if (getToastsSnapshot().length > 0) {
        setMount(true);
      }
    });
  }, [mount]);

  if (!mount) return null;

  return (
    <Suspense fallback={null}>
      <LazyToaster />
    </Suspense>
  );
}
