export interface SchemaAttribute {
    name: string;
    qname: string;
    label: string;
    description: string;
    notes: string[];
    required: boolean;
    type: string;
    values: string[];
    constraints: { name: string; value: string }[];
    fixed: string | null;
    default: string | null;
}

export interface SchemaField {
    id: string;
    parentId: string | null;
    groupId: string;
    name: string;
    qname: string;
    path: string;
    label: string;
    description: string;
    notes: string[];
    required: boolean;
    conditional: boolean;
    min: string;
    max: string;
    type: string;
    values: string[];
    constraints: { name: string; value: string }[];
    attributes: SchemaAttribute[];
    children: string[];
    example: string | null;
    sourcePath?: string;
    semantic?: { iri?: string; valueType?: string; container?: string; vocabulary?: string };
}

export interface SchemaCitation {
    text: string;
    url: string;
}

export interface SchemaDocumentation {
    syntax: 'xml' | 'json';
    fileExtension: 'xml' | 'json' | 'jsonld';
    fileLabel: string;
    schemaName: string;
    eyebrow: string;
    referenceUrl: string;
    brandUrl?: string;
    overviewUrl?: string;
    format?: string;
    formats?: { key: string; label: string; url: string }[];
    links?: { label: string; url: string }[];
    validation?: { title: string; description: string; steps: { title: string; description: string }[]; code?: string };
    locale: string;
    revision: string;
    version: string;
    namespace: string;
    schemaUrl: string;
    manifestUrl: string;
    sourceUrl: string;
    publicationUrl: string;
    citation?: SchemaCitation;
    versions: { revision: string; label: string; url: string }[];
    copy: Record<string, string>;
    introduction: {
        title: string;
        description: string;
        steps: { title: string; description: string }[];
        rules: { title: string; description: string }[];
    };
    notes: { title: string; description: string }[];
    fields: SchemaField[];
    examples: Record<'minimal' | 'full', { code: string; url: string }>;
}
