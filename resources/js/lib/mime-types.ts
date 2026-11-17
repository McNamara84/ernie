/** A separate Vite chunk keeps the complete offline catalog out of the editor shell. */
export const loadMimeTypes = () => import('../../data/iana-media-types.json').then((catalog) => catalog.default.types);

export type FormatUsage = Readonly<Record<string, number>>;
export type MimeTypeOption = { value: string; label: string };
export const EMPTY_FORMAT_USAGE: FormatUsage = {};

/** Familiar names take precedence; uncommon types get a readable subtype name. */
const FORMAT_NAMES: Readonly<Record<string, string>> = {
    'text/csv': 'CSV',
    'text/tab-separated-values': 'TSV',
    'text/plain': 'Plain text (TXT)',
    'text/markdown': 'Markdown',
    'text/html': 'HTML',
    'text/xml': 'XML (text)',
    'text/css': 'CSS',
    'text/javascript': 'JavaScript',
    'application/javascript': 'JavaScript (application)',
    'application/json': 'JSON',
    'application/ld+json': 'JSON-LD',
    'application/xml': 'XML',
    'application/xhtml+xml': 'XHTML',
    'application/pdf': 'PDF',
    'application/octet-stream': 'Binary data',
    'application/sql': 'SQL',
    'application/zip': 'ZIP archive',
    'application/gzip': 'GZIP archive',
    'application/zstd': 'Zstandard archive',
    'application/x-tar': 'TAR archive',
    'application/x-7z-compressed': '7-Zip archive',
    'application/x-bzip2': 'BZIP2 archive',
    'application/x-xz': 'XZ archive',
    'application/vnd.rar': 'RAR archive',
    'application/geo+json': 'GeoJSON',
    'application/gml+xml': 'GML',
    'application/geopackage+sqlite3': 'GeoPackage',
    'application/vnd.google-earth.kml+xml': 'KML',
    'application/vnd.google-earth.kmz': 'KMZ',
    'application/vnd.shp': 'Shapefile (SHP)',
    'application/vnd.shx': 'Shapefile index (SHX)',
    'application/vnd.dbf': 'dBASE table (DBF)',
    'application/vnd.apache.parquet': 'Parquet',
    'application/vnd.apache.arrow.file': 'Apache Arrow (file)',
    'application/vnd.apache.arrow.stream': 'Apache Arrow (stream)',
    'application/x-netcdf': 'NetCDF',
    'application/x-hdf5': 'HDF5',
    'application/x-hdf': 'HDF',
    'application/vnd.ms-excel': 'Excel (XLS)',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel (XLSX)',
    'application/msword': 'Word (DOC)',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word (DOCX)',
    'application/vnd.ms-powerpoint': 'PowerPoint (PPT)',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PowerPoint (PPTX)',
    'application/vnd.oasis.opendocument.text': 'OpenDocument Text (ODT)',
    'application/vnd.oasis.opendocument.spreadsheet': 'OpenDocument Spreadsheet (ODS)',
    'application/vnd.oasis.opendocument.presentation': 'OpenDocument Presentation (ODP)',
    'application/rtf': 'Rich text (RTF)',
    'image/jpeg': 'JPEG',
    'image/jp2': 'JPEG 2000',
    'image/png': 'PNG',
    'image/gif': 'GIF',
    'image/tiff': 'TIFF',
    'image/svg+xml': 'SVG',
    'image/webp': 'WebP',
    'image/avif': 'AVIF',
    'image/heic': 'HEIC',
    'audio/mpeg': 'MPEG audio',
    'audio/mp4': 'MP4 audio',
    'audio/vnd.wave': 'WAV audio',
    'audio/x-wav': 'WAV audio',
    'audio/flac': 'FLAC audio',
    'audio/ogg': 'Ogg audio',
    'video/mp4': 'MP4 video',
    'video/webm': 'WebM video',
    'video/ogg': 'Ogg video',
    'model/gltf+json': 'glTF (JSON)',
    'model/gltf-binary': 'glTF (binary)',
    'model/obj': 'OBJ 3D model',
    'model/stl': 'STL 3D model',
    'font/ttf': 'TrueType font (TTF)',
    'font/otf': 'OpenType font (OTF)',
    'font/woff': 'Web font (WOFF)',
    'font/woff2': 'Web font (WOFF2)',
};

export function mimeTypeLabel(value: string): string {
    const mimeType = value.split(';', 1)[0].trim().toLowerCase();
    if (FORMAT_NAMES[mimeType]) return FORMAT_NAMES[mimeType];
    if (!/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/.test(mimeType)) return value;
    const [subtype, ...suffixes] = mimeType
        .slice(mimeType.indexOf('/') + 1)
        .replace(/^(?:vnd\.|prs\.|x-)/, '')
        .split('+');
    const words = subtype
        .split(/[._-]+/)
        .filter(Boolean)
        .map((word) => (word.length <= 3 ? word.toUpperCase() : word.charAt(0).toUpperCase() + word.slice(1)));
    return words.join(' ') + suffixes.map((suffix) => ` (${suffix.toUpperCase()})`).join('');
}

const formatCollator = new Intl.Collator('en', { sensitivity: 'base', numeric: true });

export function mimeTypeOptions(types: string[], usage: FormatUsage = EMPTY_FORMAT_USAGE): MimeTypeOption[] {
    return types
        .map((value) => ({ value, label: mimeTypeLabel(value) }))
        .sort(
            (left, right) =>
                (usage[right.value] ?? 0) - (usage[left.value] ?? 0) ||
                formatCollator.compare(left.label, right.label) ||
                formatCollator.compare(left.value, right.value),
        );
}
