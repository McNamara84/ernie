import SchemaDocumentationExplorer from '@/components/schema-documentation/SchemaDocumentationExplorer';
import type { SchemaDocumentation } from '@/types/schema-documentation';

export default function DataCiteDocs({ documentation }: { documentation: SchemaDocumentation }) {
    return <SchemaDocumentationExplorer documentation={documentation} />;
}
