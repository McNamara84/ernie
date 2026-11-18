import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import IgsnCoreDocs from '@/pages/schemas/igsn-core-docs';
import type { IgsnSchemaDocumentation, IgsnSchemaField } from '@/types/igsn-core-docs';

import english from '../../../../resources/data/schema-docs/igsn-core/2.0/en.json';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/components/app-footer', () => ({ AppFooter: () => <footer>GFZ Data Services</footer> }));

const rootId = 'igsn-resource';
const nameId = `${rootId}-igsn-name`;
const sampleId = `${rootId}-igsn-sampleType`;
const field = (id: string, name: string, label: string, extra: Partial<IgsnSchemaField> = {}): IgsnSchemaField => ({
    id,
    name,
    label,
    parentId: rootId,
    groupId: id,
    qname: `igsn:${name}`,
    path: `/igsn:resource/igsn:${name}`,
    description: `Description of ${label.toLowerCase()}.`,
    notes: [],
    required: true,
    conditional: false,
    min: '1',
    max: '1',
    type: 'xs:string',
    values: [],
    constraints: [],
    attributes: [],
    children: [],
    example: `<igsn:${name}>Example</igsn:${name}>`,
    ...extra,
});
const props: IgsnSchemaDocumentation = {
    locale: 'en',
    revision: 'v1',
    version: '2.0',
    namespace: 'urn:FAIR-AIMS:Core:1',
    schemaUrl: 'https://example.org/schemas/igsn/2.0/v1/core.xsd',
    manifestUrl: 'https://example.org/schemas/igsn/2.0/v1/manifest.json',
    sourceUrl: 'https://github.com/example/schema',
    publicationUrl: 'https://doi.org/example',
    versions: [{ revision: 'v1', label: '2.0 · Published schema', url: '/schemas/igsn/2.0/v1/docs' }],
    copy: english.interface,
    introduction: english.introduction,
    notes: english.compatibility.v1,
    fields: [
        field(rootId, 'resource', 'Sample record', { parentId: null, children: [nameId, sampleId] }),
        field(nameId, 'name', 'Sample name', { max: 'unbounded' }),
        field(sampleId, 'sampleType', 'Sample type', {
            required: false,
            min: '0',
            notes: ['Choose one sample type.'],
            attributes: [
                {
                    name: 'sampletScheme',
                    qname: 'igsn:sampletScheme',
                    label: 'Sample type scheme',
                    description: 'A shared specimen vocabulary.',
                    notes: ['The PDF calls this attribute sampleTypeScheme.'],
                    required: false,
                    type: 'xs:string',
                    values: ['ExampleVocabulary'],
                    constraints: [],
                    fixed: null,
                    default: null,
                },
            ],
        }),
    ],
    examples: {
        minimal: { xml: '<igsn:resource>Minimal record</igsn:resource>', url: '/schemas/igsn/2.0/v1/docs/examples/minimal.xml' },
        full: { xml: '<igsn:resource>Extended record</igsn:resource>', url: '/schemas/igsn/2.0/v1/docs/examples/full.xml' },
    },
};

beforeEach(() => {
    window.history.replaceState({}, '', '/schemas/igsn/2.0/docs');
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => vi.restoreAllMocks());

describe('public IGSN Core documentation', () => {
    it('starts with a plain English guide and a real schema download', () => {
        render(<IgsnCoreDocs documentation={props} />);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('IGSN Core XML');
        expect(screen.getByRole('heading', { name: english.introduction.title })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Download XSD' })).toHaveAttribute('href', props.schemaUrl);
        expect(screen.getByLabelText('Documentation version')).toHaveValue('v1');
    });

    it('searches attribute descriptions and opens the matching element', async () => {
        const user = userEvent.setup();
        render(<IgsnCoreDocs documentation={props} />);
        await user.type(screen.getByRole('searchbox', { name: 'Search the schema' }), 'specimen vocabulary');
        const navigation = screen.getByRole('navigation', { name: 'In this schema' });
        expect(within(navigation).getByRole('status')).toHaveTextContent('1 matching elements');
        await user.click(within(navigation).getByRole('link', { name: /Sample type/ }));
        expect(screen.getByRole('heading', { name: 'Sample type', level: 2 })).toBeInTheDocument();
        expect(screen.getByText('igsn:sampletScheme')).toBeInTheDocument();
        expect(window.location.hash).toBe(`#${sampleId}`);
        await user.click(screen.getByText('Allowed values (1)'));
        expect(screen.getByText('ExampleVocabulary')).toBeVisible();
    });

    it('filters optional elements, reports no results and can reset the search', async () => {
        const user = userEvent.setup();
        render(<IgsnCoreDocs documentation={props} />);
        await user.click(screen.getByRole('button', { name: 'Optional' }));
        const navigation = screen.getByRole('navigation', { name: 'In this schema' });
        expect(within(navigation).queryByRole('link', { name: /Sample name/ })).not.toBeInTheDocument();
        expect(within(navigation).getByRole('link', { name: /Sample type/ })).toBeInTheDocument();
        await user.type(screen.getByRole('searchbox'), 'does-not-exist');
        expect(screen.getByText('No matching elements')).toBeInTheDocument();
        await user.click(within(navigation).getByRole('button', { name: 'All fields' }));
        expect(screen.getByRole('searchbox')).toHaveValue('');
        expect(within(navigation).getByRole('link', { name: /Sample name/ })).toBeInTheDocument();
    });

    it('finds an element by the attribute spelling used in the PDF', async () => {
        const user = userEvent.setup();
        render(<IgsnCoreDocs documentation={props} />);

        await user.type(screen.getByRole('searchbox'), 'sampleTypeScheme');

        const navigation = screen.getByRole('navigation', { name: 'In this schema' });
        expect(within(navigation).getByRole('link', { name: /Sample type/ })).toBeInTheDocument();
    });

    it('opens shareable deep links directly and handles malformed fragments', () => {
        window.history.replaceState({}, '', `#${sampleId}`);
        const view = render(<IgsnCoreDocs documentation={props} />);
        expect(screen.getByRole('heading', { name: 'Sample type', level: 2 })).toBeInTheDocument();
        view.unmount();
        window.history.replaceState({}, '', '#%invalid');
        render(<IgsnCoreDocs documentation={props} />);
        expect(screen.getByRole('heading', { name: english.introduction.title })).toBeInTheDocument();
    });

    it('switches XML examples, offers their downloads and copies the actual XML', async () => {
        const user = userEvent.setup();
        const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
        render(<IgsnCoreDocs documentation={props} />);
        await user.click(screen.getByRole('button', { name: 'XML examples' }));
        await user.click(screen.getByRole('button', { name: 'Extended record' }));
        expect(screen.getByRole('link', { name: 'Download XML' })).toHaveAttribute('href', props.examples.full.url);
        await user.click(screen.getByRole('button', { name: 'Copy XML' }));
        expect(write).toHaveBeenCalledWith(props.examples.full.xml);
        expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
    });
});
