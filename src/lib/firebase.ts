import { initializeApp } from 'firebase/app';
import { getAuth } from 'firebase/auth';
import { 
  getFirestore, 
  collection, 
  addDoc, 
  setDoc,
  getDoc,
  serverTimestamp, 
  doc, 
} from 'firebase/firestore';
import firebaseConfig from '../../firebase-applet-config.json';
import type { UserProfile } from './userProfile';

export type { UserProfile };

type FirebaseAppletConfig = {
  projectId: string;
  appId: string;
  apiKey: string;
  authDomain: string;
  firestoreDatabaseId?: string;
  storageBucket: string;
  messagingSenderId: string;
};

const config = firebaseConfig as FirebaseAppletConfig;
const app = initializeApp(config);
export const db = config.firestoreDatabaseId
  ? getFirestore(app, config.firestoreDatabaseId)
  : getFirestore(app);
export const auth = getAuth(app);

// Collection helper for registrations (leads)
export async function saveLead(lead: {
  name: string;
  email: string;
  role: 'web3' | 'real' | 'dual';
  ticketNumber: string;
}) {
  try {
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

// Collection helper for newsletter subscriptions
export async function saveSubscriber(email: string) {
  try {
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

// Save user profile to Firestore
export async function saveUserProfile(profile: UserProfile) {
  const userDocRef = doc(db, 'users', profile.uid);
  await setDoc(userDocRef, {
    ...profile,
    createdAt: serverTimestamp(),
  });
}

// Retrieve user profile from Firestore
export async function getUserProfile(uid: string): Promise<UserProfile | null> {
  try {
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
