export interface SchemaCatalog {
    locale: string;
    copy: Record<string, string>;
    entries: {
        id: string;
        title: string;
        format: 'XML' | 'JSON' | 'JSON-LD';
        kind: string;
        description: string;
        version: string;
        revision: string;
        documentationUrl: string;
        artifactPath: string;
        artifactUrl: string;
        fixedArtifactUrl: string;
        manifestUrl: string;
    }[];
    guidance: { title: string; description: string }[];
    supporting: {
        title: string;
        kind: string;
        description: string;
        artifactUrl: string;
        documentationUrl: string;
    };
}
