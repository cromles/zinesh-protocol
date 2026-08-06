/** API / istemci yetersiz bakiye mesajlarını tanır. */
export function isInsufficientBalanceMessage(message: string): boolean {
  const t = message.trim().toLocaleLowerCase('tr-TR');
  if (!t) return false;
  return (
    t.includes('yetersiz bakiye') ||
    t.includes('bakiyesi yetersiz') ||
    t.includes('bakiyen yok') ||
    t.includes('yeterli bakiye') ||
    t.includes('yeterli bakiyen') ||
    t.includes('likidite yetersiz') ||
    t.includes('teminat için yeterli')
  );
}
