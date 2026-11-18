export interface IgsnSchemaAttribute {
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

export interface IgsnSchemaField {
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
    attributes: IgsnSchemaAttribute[];
    children: string[];
    example: string | null;
}

export interface IgsnSchemaDocumentation {
    locale: string;
    revision: string;
    version: string;
    namespace: string;
    schemaUrl: string;
    manifestUrl: string;
    sourceUrl: string;
    publicationUrl: string;
    versions: { revision: string; label: string; url: string }[];
    copy: Record<string, string>;
    introduction: {
        title: string;
        description: string;
        steps: { title: string; description: string }[];
        rules: { title: string; description: string }[];
    };
    notes: { title: string; description: string }[];
    fields: IgsnSchemaField[];
    examples: Record<'minimal' | 'full', { xml: string; url: string }>;
}
