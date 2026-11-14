/** Formats accepted for article photos (no SVG: it can carry scripts). */
export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Shrinks a photo before upload: longest side ≤ maxSide, re-encoded as JPEG.
 * Phone photos (3–8 MB) become a few hundred KB, under the server limits.
 * A file already small enough, or one the browser cannot decode, is returned as is
 * (the server still validates it).
 */
export async function shrinkImage(file: File, maxSide = 1600, quality = 0.85, keepBelowBytes = 1_000_000): Promise<File> {
  if (typeof createImageBitmap !== 'function' || typeof document === 'undefined') return file;

  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    return file;
  }

  try {
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size <= keepBelowBytes) return file;

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const ctx = canvas.getContext('2d');
    if (!ctx) return file;
    // White under transparent PNG areas (JPEG has no alpha).
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!blob || blob.size >= file.size) return file;

    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
    return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
  } finally {
    bitmap.close();
  }
}
