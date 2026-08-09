import type { FirebaseApp } from 'firebase/app';
import type { Auth } from 'firebase/auth';
import type { Firestore } from 'firebase/firestore';
import type { UserProfile } from './userProfile';

export type { UserProfile };

export type FirebaseAppletConfig = {
  projectId: string;
  appId: string;
  apiKey: string;
  authDomain: string;
  firestoreDatabaseId?: string;
  storageBucket: string;
  messagingSenderId: string;
};

let app: FirebaseApp | null = null;
let authReady: Promise<Auth> | null = null;
let dbReady: Promise<Firestore> | null = null;

function envConfig(): FirebaseAppletConfig | null {
  const apiKey = import.meta.env.VITE_FIREBASE_API_KEY?.trim();
  const projectId = import.meta.env.VITE_FIREBASE_PROJECT_ID?.trim();
  if (!apiKey || !projectId) return null;

  const authDomain =
    import.meta.env.VITE_FIREBASE_AUTH_DOMAIN?.trim() || `${projectId}.firebaseapp.com`;
  const storageBucket =
    import.meta.env.VITE_FIREBASE_STORAGE_BUCKET?.trim() ||
    `${projectId}.firebasestorage.app`;

  return {
    apiKey,
    projectId,
    appId: import.meta.env.VITE_FIREBASE_APP_ID?.trim() || '',
    authDomain,
    storageBucket,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID?.trim() || '',
    firestoreDatabaseId: import.meta.env.VITE_FIREBASE_FIRESTORE_DATABASE_ID?.trim() || undefined,
  };
}

async function loadConfig(): Promise<FirebaseAppletConfig> {
  const fromEnv = envConfig();
  if (fromEnv) return fromEnv;
  const mod = await import('../../firebase-applet-config.json');
  return mod.default as FirebaseAppletConfig;
}

/** Firebase web config (env öncelikli, yoksa firebase-applet-config.json). */
export async function getFirebaseConfig(): Promise<FirebaseAppletConfig> {
  return loadConfig();
}

async function ensureApp(): Promise<FirebaseApp> {
  if (app) return app;
  const [{ initializeApp }, config] = await Promise.all([
    import('firebase/app'),
    loadConfig(),
  ]);
  app = initializeApp(config);
  return app;
}

/** Firebase Auth — Google popup ve Phone SMS OTP için dinamik yüklenir. */
export async function getFirebaseAuth(): Promise<Auth> {
  if (!authReady) {
    authReady = (async () => {
      const [{ getAuth }, firebaseApp] = await Promise.all([
        import('firebase/auth'),
        ensureApp(),
      ]);
      return getAuth(firebaseApp);
    })();
  }
  return authReady;
}

async function getFirestoreDb(): Promise<Firestore> {
  if (!dbReady) {
    dbReady = (async () => {
      const [{ getFirestore }, firebaseApp, config] = await Promise.all([
        import('firebase/firestore'),
        ensureApp(),
        loadConfig(),
      ]);
      return config.firestoreDatabaseId
        ? getFirestore(firebaseApp, config.firestoreDatabaseId)
        : getFirestore(firebaseApp);
    })();
  }
  return dbReady;
}

export async function saveLead(lead: {
  name: string;
  email: string;
  role: 'web3' | 'real' | 'dual';
  ticketNumber: string;
}) {
  try {
    const { collection, addDoc, serverTimestamp } = await import('firebase/firestore');
    const db = await getFirestoreDb();
    const leadsCol = collection(db, 'leads');
    await addDoc(leadsCol, {
      ...lead,
      createdAt: serverTimestamp(),
    });
    console.log('Lead saved successfully to Firestore');
  } catch (error) {
    console.error('Error saving lead to Firestore:', error);
  }
}

export async function saveSubscriber(email: string) {
  try {
    const { collection, addDoc, serverTimestamp } = await import('firebase/firestore');
    const db = await getFirestoreDb();
    const subscribersCol = collection(db, 'subscribers');
    await addDoc(subscribersCol, {
      email,
      createdAt: serverTimestamp(),
    });
    console.log('Subscriber saved successfully to Firestore');
  } catch (error) {
    console.error('Error saving subscriber to Firestore:', error);
  }
}

export async function saveUserProfile(profile: UserProfile) {
  const { doc, setDoc, serverTimestamp } = await import('firebase/firestore');
  const db = await getFirestoreDb();
  const userDocRef = doc(db, 'users', profile.uid);
  await setDoc(userDocRef, {
    ...profile,
    createdAt: serverTimestamp(),
  });
}

export async function getUserProfile(uid: string): Promise<UserProfile | null> {
  try {
    const { doc, getDoc } = await import('firebase/firestore');
    const db = await getFirestoreDb();
    const userDocRef = doc(db, 'users', uid);
    const docSnap = await getDoc(userDocRef);
    if (docSnap.exists()) {
      const data = docSnap.data();
      return {
        uid: data.uid,
        name: data.name,
        email: data.email,
        role: data.role as 'web3' | 'real' | 'dual',
        ticketNumber: data.ticketNumber,
        trustScore: Number(data.trustScore),
        fiziBalance: Number(data.fiziBalance),
        walletAddress: data.walletAddress,
      };
    }
    return null;
  } catch (error) {
    console.error('Error getting user profile from Firestore:', error);
    return null;
  }
}
