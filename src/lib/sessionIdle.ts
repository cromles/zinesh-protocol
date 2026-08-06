/**
 * Hareketsizlik zaman aşımı devre dışı — oturum yalnızca sunucu token süresi / manuel çıkış ile kapanır.
 */
export function bumpSessionActivity(): void {
  /* no-op */
}

export function bindSessionInactivityTimeout(_onTimeout: () => void): () => void {
  return () => {};
}
