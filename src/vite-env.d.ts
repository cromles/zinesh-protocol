/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_ZINESH_EVENT_WEBHOOK_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
