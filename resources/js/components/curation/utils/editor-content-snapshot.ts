const CLIENT_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function normalize(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(normalize);
    if (value === null || typeof value !== 'object') return value;

    return Object.fromEntries(
        Object.entries(value)
            .filter(([key, entry]) => entry !== undefined && !(key === 'id' && typeof entry === 'string' && CLIENT_UUID.test(entry)))
            .sort(([left], [right]) => left.localeCompare(right))
            .map(([key, entry]) => [key, normalize(entry)]),
    );
}

/** Compare editable content independently of generated row IDs and object key order. */
export function snapshotEditorContent(value: unknown): string {
    return JSON.stringify(normalize(value));
}

export function snapshotWithSavedDoi(snapshot: string, doi: string): string {
    const content = JSON.parse(snapshot) as { form: Record<string, unknown> };
    return snapshotEditorContent({ ...content, form: { ...content.form, doi } });
}
