/**
 * Riduce le immagini prima dell'invio: lato massimo 2000 px e JPEG di buona qualità.
 * Uno screenshot da telefono passa in genere da 1–5 MB a poche centinaia di KB, così l'invio da rete mobile è rapido.
 * GIF, file piccoli e immagini che il browser non sa decodificare restano invariati; se il risultato non è più leggero si tiene l'originale.
 */
export const CompressionMaxSide = 2000;
export const CompressionQuality = 0.82;
const MinBytes = 300 * 1024;

export async function compressImage(file) {
  if (!file.type.startsWith('image/') || /gif|svg/i.test(file.type) || file.size < MinBytes || typeof createImageBitmap !== 'function') return file;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, CompressionMaxSide / Math.max(bitmap.width, bitmap.height));
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);
    context.drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', CompressionQuality));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'immagine'}.jpg`, { type: 'image/jpeg', lastModified: file.lastModified });
  } catch {
    return file;
  }
}
