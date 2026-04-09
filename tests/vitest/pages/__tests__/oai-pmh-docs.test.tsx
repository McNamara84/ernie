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
    },
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

    it('renders resource type set badges', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText('resourcetype:Dataset')).toBeInTheDocument();
        expect(screen.getByText('resourcetype:PhysicalObject')).toBeInTheDocument();
    });

    it('renders OAI identifier example', () => {
        render(<OaiPmhDocs {...defaultProps} />);
        expect(screen.getByText('oai:ernie.gfz.de:10.5880/GFZ.1.2.2024.001')).toBeInTheDocument();
    });
});
