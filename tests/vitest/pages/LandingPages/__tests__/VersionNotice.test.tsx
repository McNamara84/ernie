import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { VersionNotice } from '@/pages/LandingPages/components/VersionNotice';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem } from '@/types/landing-page';

function relatedIdentifier(overrides: Partial<LandingPageRelatedIdentifier> = {}): LandingPageRelatedIdentifier {
    return {
        id: 1,
        identifier: '10.5880/new-version',
        identifier_type: 'DOI',
        relation_type: 'IsPreviousVersionOf',
        citation_label: null,
        ...overrides,
    };
}

function relatedItem(overrides: Partial<LandingPageRelatedItem> = {}): LandingPageRelatedItem {
    return {
        id: 10,
        related_item_type: 'Dataset',
        relation_type: 'Is Previous Version Of',
        relation_type_slug: 'IsPreviousVersionOf',
        publication_year: null,
        volume: null,
        issue: null,
        number: null,
        number_type: null,
        first_page: null,
        last_page: null,
        publisher: null,
        edition: null,
        identifier: null,
        identifier_type: null,
        related_metadata_scheme: null,
        scheme_uri: null,
        scheme_type: null,
        position: 0,
        titles: [{ id: 1, title: 'Forthcoming successor', title_type: 'MainTitle', language: 'en' }],
        creators: [],
        contributors: [],
        ...overrides,
    };
}

describe('VersionNotice', () => {
    it('does not render without a newer-version relation', () => {
        const { container } = render(<VersionNotice relatedIdentifiers={[relatedIdentifier({ relation_type: 'References' })]} relatedItems={[]} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders a prominent linked notice for IsPreviousVersionOf', () => {
        render(
            <VersionNotice
                relatedIdentifiers={[
                    relatedIdentifier({
                        identifier: 'https://doi.org/10.5880/GFZ.NEW.001',
                        citation_label: 'New dataset version',
                    }),
                ]}
            />,
        );

        expect(screen.getByRole('heading', { name: 'There is a newer version of this resource.' })).toBeInTheDocument();
        expect(screen.getByTestId('version-notice')).toHaveAttribute('data-severity', 'info');
        expect(screen.getByRole('link', { name: /New dataset version/ })).toHaveAttribute('href', 'https://doi.org/10.5880/GFZ.NEW.001');
    });

    it('uses the stronger superseded state and lists targets without identifiers as text', () => {
        render(
            <VersionNotice
                relatedIdentifiers={[
                    relatedIdentifier(),
                    relatedIdentifier({
                        id: 2,
                        identifier: 'https://example.test/superseding-resource',
                        identifier_type: 'URL',
                        relation_type: 'IsObsoletedBy',
                    }),
                ]}
                relatedItems={[relatedItem()]}
            />,
        );

        expect(screen.getByRole('heading', { name: 'This resource has been superseded.' })).toBeInTheDocument();
        expect(screen.getByTestId('version-notice')).toHaveAttribute('data-severity', 'warning');
        expect(screen.getByText('Forthcoming successor')).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(3);
    });

    it('deduplicates normalized identifiers and prefers the related-item title', () => {
        render(
            <VersionNotice
                relatedIdentifiers={[relatedIdentifier({ identifier: 'https://doi.org/10.5880/GFZ.NEW.001' })]}
                relatedItems={[
                    relatedItem({
                        identifier: '10.5880/gfz.new.001',
                        identifier_type: 'DOI',
                        titles: [{ id: 2, title: 'Canonical successor title', title_type: 'MainTitle', language: 'en' }],
                    }),
                ]}
            />,
        );

        expect(screen.getAllByRole('listitem')).toHaveLength(1);
        expect(screen.getByRole('link', { name: /Canonical successor title/ })).toHaveAttribute('href', 'https://doi.org/10.5880/GFZ.NEW.001');
    });

    it('preserves distinct version targets whose case-sensitive URL paths differ', () => {
        render(
            <VersionNotice
                relatedIdentifiers={[
                    relatedIdentifier({
                        identifier: 'https://example.test/Record',
                        identifier_type: 'URL',
                        citation_label: 'Uppercase path',
                    }),
                    relatedIdentifier({
                        id: 2,
                        identifier: 'https://example.test/record',
                        identifier_type: 'URL',
                        citation_label: 'Lowercase path',
                    }),
                ]}
            />,
        );

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(screen.getByRole('link', { name: /Uppercase path/ })).toHaveAttribute('href', 'https://example.test/Record');
        expect(screen.getByRole('link', { name: /Lowercase path/ })).toHaveAttribute('href', 'https://example.test/record');
    });

    it('renders an unsupported identifier as plain text without a broken link', () => {
        render(<VersionNotice relatedIdentifiers={[relatedIdentifier({ identifier: '123456', identifier_type: 'PMID', citation_label: null })]} />);

        expect(screen.getByText('PMID: 123456')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
