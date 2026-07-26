/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { DownloadMetadataSection } from '@/pages/LandingPages/components/DownloadMetadataSection';
import type { LandingPageMetadataLink } from '@/types/landing-page';

const canonicalMetadataLinks = [
    {
        format: 'datacite-xml',
        standard: 'DataCite',
        label: 'DataCite XML',
        url: 'https://example.com/10.5880/test/metadata/datacite.xml',
        mediaType: 'application/xml',
        profile: null,
    },
    {
        format: 'datacite-json',
        standard: 'DataCite',
        label: 'DataCite JSON',
        url: 'https://example.com/10.5880/test/metadata/datacite.json',
        mediaType: 'application/json',
        profile: null,
    },
    {
        format: 'datacite-jsonld',
        standard: 'DataCite',
        label: 'DataCite JSON-LD',
        url: 'https://example.com/10.5880/test/metadata/datacite.jsonld',
        mediaType: 'application/ld+json',
        profile: null,
    },
    {
        format: 'iso19115-3',
        standard: 'ISO 19115-3',
        label: 'ISO 19115-3:2023 XML',
        url: 'https://example.com/10.5880/test/metadata/iso-19115-3.xml',
        mediaType: 'application/xml',
        profile: 'https://schemas.isotc211.org/19115/-1/mdb/1.3',
    },
] satisfies LandingPageMetadataLink[];

describe('DownloadMetadataSection', () => {
    it('renders XML download link', () => {
        render(<DownloadMetadataSection resourceId={42} />);
        const xmlLink = screen.getByTitle('Download as DataCite XML');
        expect(xmlLink).toHaveAttribute('href', '/resources/42/export-datacite-xml');
    });

    it('renders JSON download link', () => {
        render(<DownloadMetadataSection resourceId={42} />);
        const jsonLink = screen.getByTitle('Download as DataCite JSON');
        expect(jsonLink).toHaveAttribute('href', '/resources/42/export-datacite-json');
    });

    it('renders JSON-LD download link with default URL', () => {
        render(<DownloadMetadataSection resourceId={42} />);
        const jsonLdLink = screen.getByTitle('Download as JSON-LD (Linked Data)');
        expect(jsonLdLink).toHaveAttribute('href', '/resources/42/export-jsonld');
    });

    it('uses custom JSON-LD export URL when provided', () => {
        render(<DownloadMetadataSection resourceId={42} jsonLdExportUrl="https://example.com/10.5880/test/jsonld" />);
        const jsonLdLink = screen.getByTitle('Download as JSON-LD (Linked Data)');
        expect(jsonLdLink).toHaveAttribute('href', 'https://example.com/10.5880/test/jsonld');
    });

    it('uses canonical public URLs for every DataCite representation', () => {
        render(<DownloadMetadataSection resourceId={42} metadataLinks={canonicalMetadataLinks} />);

        expect(screen.getByTitle('Download as DataCite XML')).toHaveAttribute('href', 'https://example.com/10.5880/test/metadata/datacite.xml');
        expect(screen.getByTitle('Download as DataCite JSON')).toHaveAttribute('href', 'https://example.com/10.5880/test/metadata/datacite.json');
        expect(screen.getByTitle('Download as JSON-LD (Linked Data)')).toHaveAttribute(
            'href',
            'https://example.com/10.5880/test/metadata/datacite.jsonld',
        );
    });

    it('renders a neutral ISO metadata badge and public ISO download for eligible resources', () => {
        render(<DownloadMetadataSection resourceId={42} metadataLinks={canonicalMetadataLinks} />);

        expect(screen.getByLabelText('ISO 19115-3 metadata available')).toHaveTextContent('ISO19115-3:2023 Metadata');
        expect(screen.getByRole('link', { name: 'Download ISO 19115-3:2023 metadata as XML' })).toHaveAttribute(
            'href',
            'https://example.com/10.5880/test/metadata/iso-19115-3.xml',
        );
    });

    it('does not render an ISO badge when the representation is unavailable', () => {
        render(<DownloadMetadataSection resourceId={42} metadataLinks={canonicalMetadataLinks.slice(0, 3)} />);

        expect(screen.queryByLabelText('ISO 19115-3 metadata available')).not.toBeInTheDocument();
        expect(screen.queryByTitle('Download as ISO 19115-3 XML')).not.toBeInTheDocument();
    });

    it('renders DataCite logo', () => {
        render(<DownloadMetadataSection resourceId={42} />);

        const logo = screen.getByAltText('DataCite');
        expect(logo).toBeInTheDocument();
        expect(logo).toHaveAttribute('src', '/images/datacite-logo.png');
        expect(logo).toHaveClass('h-8');
        expect(logo.closest('picture')?.querySelector('source')).toHaveAttribute('srcset', '/images/datacite-logo-light.svg');
    });
});
