import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import sharp from 'sharp';
import toIco from 'to-ico';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const brandDir = path.join(root, 'public', 'brand');
fs.mkdirSync(brandDir, { recursive: true });
const candidates = ['zinesh-logo.png', 'zinesh-logo.jpg', 'zinesh-logo.webp'];
const src = candidates.map((name) => path.join(brandDir, name)).find((p) => fs.existsSync(p));
const outDir = path.join(root, 'public');

/** Google / favicon: kare çerçeve + Z — küçük boyutlarda kalın çizgi (SERP okunurluğu). */
function googleMarkSvg(size = 512) {
  const isSmall = size <= 48;
  const pad = Math.round(size * (isSmall ? 0.14 : 0.2));
  const arm = Math.round(size * (isSmall ? 0.12 : 0.1));
  const stroke = isSmall ? Math.max(2, Math.round(size * 0.09)) : Math.max(2, Math.round(size * 0.016));
  const fontSize = isSmall ? Math.round(size * 0.44) : Math.round(size * 0.26);
  const textY = Math.round(size * (isSmall ? 0.62 : 0.58));
  const x1 = pad;
  const x2 = size - pad;
  const y1 = pad;
  const y2 = size - pad;
  const xa = x1 + arm;
  const ya = y1 + arm;
  const xb = x2 - arm;
  const yb = y2 - arm;

  return Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <rect width="${size}" height="${size}" fill="#020204"/>
  <g fill="none" stroke="#ffffff" stroke-width="${stroke}" stroke-linecap="square" stroke-linejoin="miter">
    <polyline points="${x1},${ya} ${x1},${y1} ${xa},${y1}"/>
    <polyline points="${xb},${y1} ${x2},${y1} ${x2},${ya}"/>
    <polyline points="${x2},${yb} ${x2},${y2} ${xb},${y2}"/>
    <polyline points="${xa},${y2} ${x1},${y2} ${x1},${yb}"/>
  </g>
  <text x="${size / 2}" y="${textY}" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="${fontSize}" font-weight="700" fill="#ffffff">Z</text>
</svg>`);
}

async function starfieldHeaderMark() {
  if (!src) return null;
  const meta = await sharp(src).metadata();
  const w = meta.width ?? 1024;
  const h = meta.height ?? 1024;
  const crop = Math.round(Math.min(w, h) * 0.52);
  return sharp(src).extract({
    left: Math.round((w - crop) / 2),
    top: Math.round((h - crop) / 2),
    width: crop,
    height: crop,
  });
}

const googleMark = sharp(googleMarkSvg(512));

await googleMark
  .clone()
  .png({ compressionLevel: 9 })
  .toFile(path.join(outDir, 'google-logo.png'));
console.log('wrote google-logo.png');

await googleMark
  .clone()
  .resize(1024, 1024)
  .png({ compressionLevel: 9 })
  .toFile(path.join(outDir, 'logo-1024.png'));
console.log('wrote logo-1024.png');

await googleMark
  .clone()
  .resize(1024, 1024)
  .jpeg({ quality: 92 })
  .toFile(path.join(outDir, 'og-image.jpg'));
console.log('wrote og-image.jpg');

const sizes = [16, 32, 48, 96, 192, 512];
const pngBuffers = [];

for (const size of sizes) {
  const file = path.join(outDir, `favicon-${size}x${size}.png`);
  await sharp(googleMarkSvg(size)).png({ compressionLevel: 9 }).toFile(file);
  console.log('wrote', path.basename(file));
  if (size <= 96) {
    pngBuffers.push(await sharp(file).png().toBuffer());
  }
}

await sharp(googleMarkSvg(192)).png().toFile(path.join(outDir, 'favicon.png'));
console.log('wrote favicon.png');

await googleMark
  .clone()
  .resize(256, 256)
  .png({ compressionLevel: 9 })
  .toFile(path.join(brandDir, 'zinesh-mark.png'));
console.log('wrote brand/zinesh-mark.png');

const headerMark = await starfieldHeaderMark();
if (headerMark) {
  const header256 = headerMark.clone().resize(256, 256);
  await header256.png({ compressionLevel: 9 }).toFile(path.join(brandDir, 'zinesh-logo-header.png'));
  console.log('wrote brand/zinesh-logo-header.png');

  for (const size of [64, 128, 256]) {
    const resized = header256.clone().resize(size, size);
    await resized.png({ compressionLevel: 9 }).toFile(path.join(brandDir, `zinesh-logo-header-${size}.png`));
    await resized.webp({ quality: 82 }).toFile(path.join(brandDir, `zinesh-logo-header-${size}.webp`));
    console.log(`wrote brand/zinesh-logo-header-${size}.png/webp`);
  }
}

if (src) {
  const logoBuffer = await sharp(src).toBuffer();
  const fullLogo = sharp(logoBuffer);
  const mainOut = path.join(brandDir, 'zinesh-logo.png');

  if (path.resolve(src) !== path.resolve(mainOut)) {
    await fullLogo.clone().resize(512, 512).png({ compressionLevel: 9 }).toFile(mainOut);
    console.log('wrote brand/zinesh-logo.png');
  }

  for (const size of [128, 256, 512]) {
    const resized = fullLogo.clone().resize(size, size);
    await resized.png({ compressionLevel: 9 }).toFile(path.join(brandDir, `zinesh-logo-${size}.png`));
    await resized.webp({ quality: 82 }).toFile(path.join(brandDir, `zinesh-logo-${size}.webp`));
    console.log(`wrote brand/zinesh-logo-${size}.png/webp`);
  }
}

const tetherSrc = path.join(brandDir, 'tether-usdt.png');
if (fs.existsSync(tetherSrc)) {
  const tether = sharp(tetherSrc);
  for (const size of [20, 40, 80]) {
    const resized = tether.clone().resize(size, size);
    await resized.png({ compressionLevel: 9 }).toFile(path.join(brandDir, `tether-usdt-${size}.png`));
    await resized.webp({ quality: 82 }).toFile(path.join(brandDir, `tether-usdt-${size}.webp`));
    console.log(`wrote brand/tether-usdt-${size}.png/webp`);
  }
}

const founderPhoto = path.join(root, 'public', 'kurucu', 'yasin-karademir.jpg');
if (fs.existsSync(founderPhoto)) {
  const photo = sharp(founderPhoto);
  await photo.clone().webp({ quality: 82 }).toFile(path.join(root, 'public', 'kurucu', 'yasin-karademir.webp'));
  console.log('wrote kurucu/yasin-karademir.webp');

  for (const width of [280, 560, 992]) {
    await photo
      .clone()
      .resize({ width, withoutEnlargement: true })
      .webp({ quality: 82 })
      .toFile(path.join(root, 'public', 'kurucu', `yasin-karademir-${width}.webp`));
    await photo
      .clone()
      .resize({ width, withoutEnlargement: true })
      .jpeg({ quality: 85 })
      .toFile(path.join(root, 'public', 'kurucu', `yasin-karademir-${width}.jpg`));
    console.log(`wrote kurucu/yasin-karademir-${width}.webp/jpg`);
  }
}

const ico = await toIco(pngBuffers);
fs.writeFileSync(path.join(outDir, 'favicon.ico'), ico);
console.log('wrote favicon.ico', ico.length, 'bytes');

console.log('done');
