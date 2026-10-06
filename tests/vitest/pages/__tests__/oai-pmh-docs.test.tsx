import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import OaiPmhDocs from '@/pages/oai-pmh/docs';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

const defaultProps = {
    baseUrl: 'https://ernie.gfz.de/oai-pmh',
    projectSet: {
        spec: 'epos-msl',
        name: 'EPOS-MSL Project',
        description: 'Published resources with the complete free keyword EPOS or MSL (or both).',
    },
    adminEmail: 'datapub@gfz.de',
    metadataFormats: {
        oai_dc: {
            schema: 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
            namespace: 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        },
        oai_datacite: {
            schema: 'https://schema.datacite.org/meta/kernel-4.7/metadata.xsd',
            namespace: 'http://datacite.org/schema/kernel-4',
        },
        iso19115_3: {
            schema: 'https://schemas.isotc211.org/19115/-1/mdb/1.3.0/mdb.xsd',
            namespace: 'https://schemas.isotc211.org/19115/-1/mdb/1.3',
        },
    },
    isoEligibleResourceTypeSlugs: ['dataset', 'physical-object', 'software'],
    resourceTypeSlugs: ['collection', 'dataset', 'image', 'physical-object', 'software', 'text'],
    identifierPrefix: 'oai:ernie.gfz.de',
    pageSize: 100,
    tokenTtlHours: 24,
};

describe('OaiPmhDocs', () => {
    it('renders the page heading', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByRole('heading', { name: /oai-pmh harvesting endpoint/i })).toBeInTheDocument();
    });

    it('renders the base URL', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText(defaultProps.baseUrl)).toBeInTheDocument();
    });

    it('renders the admin email link', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        const emailLink = screen.getByRole('link', { name: defaultProps.adminEmail });
        expect(emailLink).toHaveAttribute('href', `mailto:${defaultProps.adminEmail}`);
    });

    it('renders all six OAI-PMH verb badges', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        for (const verb of ['Identify', 'ListMetadataFormats', 'ListSets', 'ListIdentifiers', 'ListRecords', 'GetRecord']) {
            expect(screen.getByText(verb, { selector: '[data-slot="badge"]' })).toBeInTheDocument();
        }
    });

    it('renders metadata format prefixes', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getAllByText('oai_dc').length).toBeGreaterThanOrEqual(1);
        expect(screen.getAllByText('oai_datacite').length).toBeGreaterThanOrEqual(1);
        expect(screen.getAllByText('iso19115_3').length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('ISO 19115-3:2023 (selective)')).toBeInTheDocument();
    });

    it('documents the selectively eligible ISO resource types', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        const eligibleTypes = screen.getByLabelText('ISO 19115-3 eligible resource types');
        expect(eligibleTypes).toHaveTextContent('datasetphysical-objectsoftware');
    });

    it('renders section headings', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText('Supported Verbs')).toBeInTheDocument();
        expect(screen.getByText('Metadata Formats')).toBeInTheDocument();
        expect(screen.getByText('Sets (Selective Harvesting)')).toBeInTheDocument();
        expect(screen.getByText('Example Requests')).toBeInTheDocument();
        expect(screen.getByText('Resumption Tokens (Pagination)')).toBeInTheDocument();
        expect(screen.getByText('Selective Harvesting')).toBeInTheDocument();
        expect(screen.getByText('Deleted Records')).toBeInTheDocument();
        expect(screen.getByText('Best Practices for Harvesters')).toBeInTheDocument();
        expect(screen.getByText('OAI Identifier Format')).toBeInTheDocument();
    });

    it('renders resource type set badges from props', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText('resourcetype:dataset')).toBeInTheDocument();
        expect(screen.getByText('resourcetype:physical-object')).toBeInTheDocument();
    });

    it('renders OAI identifier example', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText(`${defaultProps.identifierPrefix}:10.5880/GFZ.1.2.2024.001`)).toBeInTheDocument();
    });

    it('documents the project keyword rules and complete inventory formats', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        const project = screen.getByLabelText('EPOS-MSL project set');
        expect(project).toHaveTextContent('EPOS-MSL Project');
        expect(project).toHaveTextContent(defaultProps.projectSet.description);
        expect(project).toHaveTextContent('Resources with both keywords appear once');
        expect(project).toHaveTextContent('Blank subject attributes count as absent');
        expect(project).toHaveTextContent('subject language does not affect membership');
        expect(project).toHaveTextContent('EPOS-MSL and EPOS project do not');
        expect(project).toHaveTextContent('Controlled EPOS-MSL vocabulary terms do not');
        expect(project).toHaveTextContent('noRecordsMatch');
        expect(project).toHaveTextContent('iso19115_3 covers only eligible resources');
        expect(project).toHaveTextContent('<setSpec>epos-msl</setSpec>');
    });

    it('uses the configured base URL and project spec in copyable requests', () => {
        const baseUrl = 'https://example.org/harvest';
        render(<OaiPmhDocs {...defaultProps} baseUrl={baseUrl} />);
        expect(screen.getByText(`${baseUrl}?verb=ListRecords&metadataPrefix=oai_datacite&set=epos-msl`)).toBeInTheDocument();
        expect(screen.getAllByText(`${baseUrl}?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl`)).toHaveLength(2);
    });

    it('documents safe reconciliation and set exits without false deletions', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText('Keeping an EPOS-MSL Harvest in Sync')).toBeInTheDocument();
        expect(screen.getByText(/daily incremental updates and a weekly complete/)).toBeInTheDocument();
        expect(screen.getByText(/Never remove local records based on an incomplete or failed harvest/)).toBeInTheDocument();
        expect(screen.getByText(/Technical errors do not confirm removal/)).toBeInTheDocument();
        expect(screen.getByText(/Keyword removal does not generate a deleted header/)).toBeInTheDocument();
        expect(screen.getByText(/later pages do not extend it/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'OAI-PMH 2.0 sets' })).toHaveAttribute(
            'href',
            'https://www.openarchives.org/OAI/openarchivesprotocol.html#Set',
        );
    });
});
