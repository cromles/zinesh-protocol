/** Bot / sahte numara kalıpları — sunucu kurallarıyla uyumlu */
export function rejectObviousFakeNumber(digits: string): string | null {
  const d = digits.replace(/\D/g, '');
  if (d.length < 6) return null;

  if (/^(\d)\1+$/.test(d)) {
    return 'Aynı rakamın tekrar ettiği numaralar kabul edilmez.';
  }

  let asc = true;
  let desc = true;
  for (let i = 1; i < d.length; i++) {
    const prev = Number(d[i - 1]);
    const cur = Number(d[i]);
    if (cur !== prev + 1) asc = false;
    if (cur !== prev - 1) desc = false;
  }
  if (asc || desc) {
    return 'Ardışık rakamlardan oluşan numaralar (123456… / 987654…) kabul edilmez.';
  }

  if (d.length >= 8 && new Set(d.split('')).size <= 2) {
    return 'Geçersiz numara formatı — çok az farklı rakam içeriyor.';
  }

  return null;
}
