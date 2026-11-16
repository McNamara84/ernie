/** A separate Vite chunk keeps the complete offline catalog out of the editor shell. */
export const loadMimeTypes = () => import('../../data/iana-media-types.json').then((catalog) => catalog.default.types);
