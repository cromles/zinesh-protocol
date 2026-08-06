/** OAuth sağlayıcı altyapısı */
export type OAuthProviderId = 'google';

export interface OAuthProviderConfig {
  id: OAuthProviderId;
  label: string;
  enabled: boolean;
  /** Backend action adı */
  authAction: string;
}

export const OAUTH_PROVIDERS: Record<OAuthProviderId, OAuthProviderConfig> = {
  google: {
    id: 'google',
    label: 'Google ile giriş',
    enabled: true,
    authAction: 'oauth_google',
  },
};

export function isOAuthProviderEnabled(id: OAuthProviderId): boolean {
  return OAUTH_PROVIDERS[id]?.enabled === true;
}

export function getEnabledOAuthProviders(): OAuthProviderConfig[] {
  return Object.values(OAUTH_PROVIDERS).filter((p) => p.enabled);
}
