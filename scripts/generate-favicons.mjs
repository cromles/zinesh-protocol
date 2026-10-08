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

if (!src) throw new Error('The Zinesh logo source public/brand/zinesh-logo.png is required.');

/** Center-crop to a square while keeping the complete logo artwork in frame. */
async function squareLogo(size) {
  const { width = size, height = size } = await sharp(src).metadata();
  const side = Math.min(width, height);
  return sharp(src)
    .extract({ left: Math.floor((width - side) / 2), top: Math.floor((height - side) / 2), width: side, height: side })
    .resize(size, size);
}

async function starfieldHeaderMark() {
  if (!src) return null;
  const meta = await sharp(src).metadata();
  const w = meta.width ?? 1024;
  const h = meta.height ?? 1024;
  const crop = Math.round(Math.min(w, h) * 0.82);
  return sharp(src).extract({
    left: Math.round((w - crop) / 2),
    top: Math.round((h - crop) / 2),
    width: crop,
    height: crop,
  });
}

await squareLogo(512)
  .png({ compressionLevel: 9 })
  .toFile(path.join(outDir, 'google-logo.png'));
console.log('wrote google-logo.png');

await squareLogo(1024)
  .png({ compressionLevel: 9 })
  .toFile(path.join(outDir, 'logo-1024.png'));
console.log('wrote logo-1024.png');

const ogSource = path.join(outDir, 'og-image-source.png');
if (fs.existsSync(ogSource)) {
  await sharp(ogSource)
    .resize(1200, 1200, { fit: 'inside', withoutEnlargement: false })
    .jpeg({ quality: 90, mozjpeg: true })
    .toFile(path.join(outDir, 'og-image.jpg'));
  console.log('wrote og-image.jpg (from og-image-source.png)');
} else {
  await squareLogo(1024)
    .jpeg({ quality: 92 })
    .toFile(path.join(outDir, 'og-image.jpg'));
  console.log('wrote og-image.jpg');
}

const sizes = [16, 32, 48, 96, 192, 512];
const pngBuffers = [];

for (const size of sizes) {
  const file = path.join(outDir, `favicon-${size}x${size}.png`);
  await squareLogo(size)
    .png({ compressionLevel: 9 })
    .toFile(file);
  console.log('wrote', path.basename(file));
  if (size <= 96) {
    pngBuffers.push(await sharp(file).png().toBuffer());
  }
}

await squareLogo(192)
  .png()
  .toFile(path.join(outDir, 'favicon.png'));
console.log('wrote favicon.png');

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
