export interface DataCiteDocsOverview {
    locale: string;
    revision: string;
    version: string;
    copy: Record<string, string>;
    home: {
        title: string;
        tagline: string;
        lead: string;
        heading: string;
        description: string;
        cards: { key: string; title: string; description: string; action: string }[];
        differencesTitle: string;
        differences: { title: string; description: string }[];
        source: string;
        igsn: string;
        revision: string;
    };
    formats: { key: string; label: string; url: string }[];
    versions: { revision: string; label: string; url: string }[];
    schemaUrl: string;
    contextUrl: string;
    manifestUrl: string;
    sourceUrl: string;
    igsnUrl: string;
}
