import SchemaDocumentationExplorer from '@/components/schema-documentation/SchemaDocumentationExplorer';
import type { IgsnSchemaDocumentation } from '@/types/igsn-core-docs';

export default function IgsnCoreDocs({ documentation }: { documentation: IgsnSchemaDocumentation }) {
    return (
        <SchemaDocumentationExplorer
            documentation={{
                ...documentation,
                syntax: 'xml',
                fileExtension: 'xml',
                fileLabel: 'XML',
                schemaName: 'IGSN Core',
                eyebrow: 'FAIR AIMS',
                referenceUrl: documentation.schemaUrl.replace(/core\.xsd$/, 'docs'),
                examples: {
                    minimal: { code: documentation.examples.minimal.xml, url: documentation.examples.minimal.url },
                    full: { code: documentation.examples.full.xml, url: documentation.examples.full.url },
                },
            }}
        />
    );
}
