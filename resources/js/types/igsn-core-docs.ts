import type { SchemaDocumentation } from './schema-documentation';

export type { SchemaAttribute as IgsnSchemaAttribute, SchemaField as IgsnSchemaField } from './schema-documentation';

export interface IgsnSchemaDocumentation extends Omit<
    SchemaDocumentation,
    'syntax' | 'fileExtension' | 'fileLabel' | 'schemaName' | 'eyebrow' | 'referenceUrl' | 'examples'
> {
    examples: Record<'minimal' | 'full', { xml: string; url: string }>;
}
