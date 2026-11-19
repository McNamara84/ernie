export const elmoUrl = 'https://dataservices.gfz.de/elmo';

export const homepageLinkGroups = [
    {
        title: 'Services',
        links: [
            { label: 'Portal / Data Catalogue', href: 'https://dataservices.gfz.de/portal/' },
            { label: 'Data Centres', href: '/data-centres' },
            { label: 'ELMO - GFZ Metadata Editor 2.0', href: elmoUrl },
            { label: 'ELMO-MSL - GFZ Metadata Editor for the EPOS Multi-scale laboratories', href: 'https://dataservices.gfz.de/elmo-msl' },
            { label: 'GFZ IGSN Service', href: 'https://dataservices.gfz-potsdam.de/web/samples/introduction' },
        ],
    },
    {
        title: 'Guides',
        links: [
            { label: 'Data Description Templates', href: 'https://gfzpublic.gfz.de/pubman/item/item_5007103' },
            { label: 'Publication Instructions', href: 'https://dataservices.gfz-potsdam.de/web/publish-data/publication-instructions' },
            { label: 'ELMO Guide (GFZ Metadata Editor 2.0)', href: 'https://dataservices.gfz.de/elmo/doc/help.php' },
            { label: 'Quick Start Guide for Data Publications', href: 'https://dataservices.gfz-potsdam.de/web/publish-data/quick-start-guide' },
            { label: 'File Instructions', href: 'https://dataservices.gfz-potsdam.de/web/publish-data/data-file-instructions' },
            { label: 'FAIR SAMPLES Template (for IGSN)', href: 'https://dataservices.gfz-potsdam.de/web/samples/fair-samples-template' },
        ],
    },
    {
        title: 'External Links to our data',
        links: [
            { label: 'EPOS MSL Portal', href: 'https://epos-msl.uu.nl/' },
            { label: 'B2Find (EUDAT)', href: 'http://b2find.eudat.eu/group' },
            { label: 'DataCite Commons', href: 'https://commons.datacite.org/' },
            { label: 'ScholeXplorer (OpenAIRE)', href: 'https://scholexplorer.openaire.eu/#/' },
            { label: 'Google Dataset Search', href: 'https://datasetsearch.research.google.com/' },
        ],
    },
];

export interface ScienceTopic {
    slug: string;
    label: string;
    image: string;
    href: string;
}
