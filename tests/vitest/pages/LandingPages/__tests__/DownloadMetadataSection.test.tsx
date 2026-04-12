/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DownloadMetadataSection } from '@/pages/LandingPages/components/DownloadMetadataSection';

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

    it('renders DataCite logo', () => {
        render(<DownloadMetadataSection resourceId={42} />);
        expect(screen.getByAltText('DataCite')).toBeInTheDocument();
    });
});
