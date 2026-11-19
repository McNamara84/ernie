import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@tests/vitest/utils/render';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DataCiteDocs from '@/pages/schemas/datacite-docs';
import DataCiteOverview from '@/pages/schemas/datacite-docs-overview';
import type { DataCiteDocsOverview } from '@/types/datacite-docs';
import type { SchemaDocumentation, SchemaField } from '@/types/schema-documentation';

import english from '../../../../resources/data/schema-docs/datacite/4.7/en.json';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
vi.mock('@/components/app-footer', () => ({ AppFooter: () => <footer>GFZ Data Services</footer> }));

const field = (id: string, name: string, extra: Partial<SchemaField> = {}): SchemaField => ({
    id,
    name,
    qname: name,
    parentId: 'datacite-root',
    groupId: id,
    path: `$.${name}`,
    label: name,
    description: 'A metadata field.',
    notes: [],
    required: true,
    conditional: false,
    min: '1',
    max: '1',
    type: 'string',
    values: [],
    constraints: [],
    attributes: [],
    children: [],
    example: '{"name":"Example"}',
    ...extra,
});
const doc: SchemaDocumentation = {
    syntax: 'json',
    fileExtension: 'json',
    fileLabel: 'JSON',
    schemaName: 'DataCite',
    eyebrow: 'GFZ / ERNIE PROFILE',
    referenceUrl: '/schemas/datacite/4.7/v1/docs/json',
    brandUrl: '/schemas/datacite/4.7/docs',
    overviewUrl: '/schemas/datacite/4.7/docs',
    format: 'json',
    formats: [
        { key: 'json', label: 'JSON', url: '/schemas/datacite/4.7/docs/json' },
        { key: 'jsonld', label: 'JSON-LD', url: '/schemas/datacite/4.7/docs/jsonld' },
    ],
    locale: 'en',
    revision: 'v1',
    version: '4.7',
    namespace: 'JSON Schema Draft 2020-12',
    schemaUrl: '/schemas/datacite/4.7/v1/schema.json',
    manifestUrl: '/schemas/datacite/4.7/v1/manifest.json',
    sourceUrl: 'https://schema.datacite.org/meta/kernel-4.7/',
    publicationUrl: 'https://doi.org/10.14454/qdd3-ps68',
    citation: english.citation,
    versions: [{ revision: 'v1', label: english.revisions.v1, url: '/schemas/datacite/4.7/v1/docs/json' }],
    copy: english.interface,
    introduction: english.introduction.json,
    notes: english.notes,
    validation: english.validation.json,
    fields: [
        field('datacite-root', 'attributes', { label: 'DataCite attributes', path: '$', parentId: null, children: ['datacite-creators-name'] }),
        field('datacite-creators-name', 'name', { label: 'Creator name', path: '$.creators[].name' }),
    ],
    examples: { minimal: { code: '{"minimal":true}', url: '/minimal.json' }, full: { code: '{"extended":true}', url: '/full.json' } },
};
const overview: DataCiteDocsOverview = {
    locale: 'en',
    revision: 'v1',
    version: '4.7',
    copy: english.interface,
    home: english.home,
    formats: doc.formats!,
    versions: doc.versions,
    schemaUrl: doc.schemaUrl,
    contextUrl: '/schemas/datacite/4.7/v1/context.jsonld',
    manifestUrl: doc.manifestUrl,
    sourceUrl: doc.sourceUrl,
    igsnUrl: '/schemas/igsn/2.0/docs',
    citation: english.citation,
};

beforeEach(() => {
    window.history.replaceState({}, '', '/schemas/datacite/4.7/docs/json');
    Element.prototype.scrollIntoView = vi.fn();
});

describe('DataCite documentation', () => {
    it('keeps the full DataCite citation available when a field is selected', () => {
        window.history.replaceState({}, '', '#datacite-creators-name');
        render(<DataCiteDocs documentation={doc} />);
        expect(screen.getByRole('region', { name: english.interface.citationTitle })).toHaveTextContent(english.citation.text);
        expect(screen.getByRole('link', { name: english.citation.url })).toHaveAttribute('href', english.citation.url);
        expect(screen.getByRole('link', { name: english.interface.citationLink })).toHaveAttribute('href', '#schema-citation');
    });

    it('offers both format guides and explains their different validation roles', () => {
        render(<DataCiteOverview documentation={overview} />);
        expect(screen.getByRole('link', { name: 'Explore JSON' })).toHaveAttribute('href', '/schemas/datacite/4.7/docs/json');
        expect(screen.getByRole('link', { name: 'Explore JSON-LD' })).toHaveAttribute('href', '/schemas/datacite/4.7/docs/jsonld');
        expect(screen.getByText(/does not check DataCite required fields/)).toBeVisible();
        expect(screen.getByRole('link', { name: 'IGSN Core XML guide' })).toHaveAttribute('href', '/schemas/igsn/2.0/docs');
        expect(screen.getByRole('region', { name: english.interface.citationTitle })).toHaveTextContent(english.citation.text);
    });

    it.each(['json', 'jsonld'] as const)('opens the first matching %s field with Enter', async (format) => {
        const user = userEvent.setup();
        render(<DataCiteDocs documentation={{ ...doc, format }} />);
        await user.type(screen.getByRole('searchbox'), 'name');
        await user.keyboard('{Enter}');
        expect(screen.getByRole('heading', { name: 'Creator name', level: 2 })).toBeVisible();
        expect(window.location.hash).toBe('#datacite-creators-name');
        expect(screen.getByRole('main')).toHaveFocus();
    });

    it('keeps the selected DataCite field in the format-switch link', async () => {
        const user = userEvent.setup();
        render(<DataCiteDocs documentation={doc} />);
        await user.type(screen.getByRole('searchbox'), 'name');
        await user.click(within(screen.getByRole('navigation', { name: 'In this profile' })).getByRole('link', { name: /Creator name/ }));
        expect(screen.getByRole('heading', { name: 'Creator name', level: 2 })).toBeVisible();
        expect(screen.getByRole('link', { name: 'JSON-LD' })).toHaveAttribute('href', '/schemas/datacite/4.7/docs/jsonld#datacite-creators-name');
        expect(screen.getByText('$.creators[].name', { exact: true })).toBeVisible();
    });

    it('shows the actual JSON-LD path, its DataCite source and its property IRI', () => {
        window.history.replaceState({}, '', '#datacite-creators-name');
        render(
            <DataCiteDocs
                documentation={{
                    ...doc,
                    format: 'jsonld',
                    fileLabel: 'JSON-LD',
                    fileExtension: 'jsonld',
                    copy: { ...doc.copy, ...english.jsonldInterface },
                    introduction: english.introduction.jsonld,
                    fields: [
                        doc.fields[0],
                        {
                            ...doc.fields[1],
                            path: '$.creators.creator[].creatorName.value',
                            sourcePath: '$.creators[].name',
                            semantic: { iri: 'https://w3id.org/tib/datacite/property/creatorName' },
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText('$.creators.creator[].creatorName.value', { exact: true })).toBeVisible();
        expect(screen.getByText('$.creators[].name', { exact: true })).toBeVisible();
        expect(screen.getByRole('link', { name: 'https://w3id.org/tib/datacite/property/creatorName' })).toHaveAttribute(
            'href',
            'https://w3id.org/tib/datacite/property/creatorName',
        );
    });

    it('explains a field that is unavailable in the selected format', () => {
        window.history.replaceState({}, '', '#datacite-container');
        render(<DataCiteDocs documentation={doc} />);
        expect(screen.getByRole('status')).toHaveTextContent(english.interface.fieldNotInFormat);
        expect(screen.getByRole('heading', { name: english.introduction.json.title })).toBeVisible();
    });

    it('finds a Linked Data field by its canonical DataCite JSON path', async () => {
        const user = userEvent.setup();
        render(
            <DataCiteDocs
                documentation={{
                    ...doc,
                    format: 'jsonld',
                    fields: [doc.fields[0], { ...doc.fields[1], sourcePath: '$.types', path: '$.resourceType', label: 'Resource classification' }],
                }}
            />,
        );
        await user.type(screen.getByRole('searchbox'), '$.types');
        expect(
            within(screen.getByRole('navigation', { name: 'In this profile' })).getByRole('link', { name: /Resource classification/ }),
        ).toBeVisible();
    });

    it('downloads and copies the selected JSON example', async () => {
        const user = userEvent.setup();
        const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
        render(<DataCiteDocs documentation={doc} />);
        await user.click(screen.getByRole('button', { name: 'JSON examples' }));
        await user.click(screen.getByRole('button', { name: 'Extended record' }));
        expect(screen.getByRole('link', { name: 'Download JSON' })).toHaveAttribute('href', '/full.json');
        await user.click(screen.getByRole('button', { name: 'Copy JSON' }));
        expect(write).toHaveBeenCalledWith(doc.examples.full.code);
    });

    it('finds a field by an allowed value', async () => {
        const user = userEvent.setup();
        render(
            <DataCiteDocs
                documentation={{
                    ...doc,
                    fields: [doc.fields[0], { ...doc.fields[1], label: 'General resource type', values: ['Dataset', 'Poster'] }],
                }}
            />,
        );
        await user.type(screen.getByRole('searchbox'), 'poster');
        expect(
            within(screen.getByRole('navigation', { name: 'In this profile' })).getByRole('link', { name: /General resource type/ }),
        ).toBeVisible();
    });
});
