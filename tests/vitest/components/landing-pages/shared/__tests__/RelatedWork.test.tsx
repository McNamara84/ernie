import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RelatedWork from '@/components/landing-pages/shared/RelatedWork';

describe('RelatedWork', () => {
    const mockResourceWithMultipleTypes = {
        related_identifiers: [
            {
                id: 1,
                identifier: '10.1234/example.citation',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                related_title: 'A Great Scientific Paper',
            },
            {
                id: 2,
                identifier: '10.5678/another.paper',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                related_title: 'Another Citation',
            },
            {
                id: 3,
                identifier: '10.9999/reference.work',
                identifier_type: 'DOI',
                relation_type: 'References',
                related_title: 'Referenced Dataset',
            },
            {
                id: 4,
                identifier: '10.1111/source.data',
                identifier_type: 'DOI',
                relation_type: 'IsDerivedFrom',
            },
            {
                id: 5,
                identifier: 'https://example.com/documentation',
                identifier_type: 'URL',
                relation_type: 'IsDocumentedBy',
                related_title: 'Documentation Page',
            },
        ],
    };

    const mockResourceWithVariousIdentifierTypes = {
        related_identifiers: [
            {
                identifier: 'doi:10.1234/test',
                identifier_type: 'DOI',
                relation_type: 'Cites',
            },
            {
                identifier: 'https://example.com/resource',
                identifier_type: 'URL',
                relation_type: 'References',
            },
            {
                identifier: '2021.01234',
                identifier_type: 'arXiv',
                relation_type: 'Cites',
                related_title: 'Preprint Article',
            },
            {
                identifier: '12345678',
                identifier_type: 'PMID',
                relation_type: 'References',
            },
            {
                identifier: '978-3-16-148410-0',
                identifier_type: 'ISBN',
                relation_type: 'Cites',
            },
            {
                identifier: 'hdl:1234/5678',
                identifier_type: 'Handle',
                relation_type: 'IsDerivedFrom',
            },
        ],
    };

    const mockResourceSingleIdentifier = {
        related_identifiers: [
            {
                identifier: '10.1234/single',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                related_title: 'Single Related Work',
            },
        ],
    };

    const mockResourceNoIdentifiers = {
        related_identifiers: [],
    };

    const mockResourceMissingIdentifiers = {};

    describe('Rendering', () => {
        it('should render related work section with heading', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByRole('heading', { name: /^related work$/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} heading="Citations & References" />);

            expect(screen.getByRole('heading', { name: /citations & references/i })).toBeInTheDocument();
        });

        it('should not render when no related identifiers', () => {
            const { container } = render(<RelatedWork resource={mockResourceNoIdentifiers} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when related_identifiers property missing', () => {
            const { container } = render(<RelatedWork resource={mockResourceMissingIdentifiers} />);

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Grouping by Relation Type', () => {
        it('should group identifiers by relation type', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByRole('heading', { name: /citations/i })).toBeInTheDocument();
            expect(screen.getByRole('heading', { name: /references/i })).toBeInTheDocument();
            expect(screen.getByRole('heading', { name: /derived from/i })).toBeInTheDocument();
            expect(screen.getByRole('heading', { name: /documentation/i })).toBeInTheDocument();
        });

        it('should display count for each relation type', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByText(/\(2\)/)).toBeInTheDocument(); // Cites has 2
            expect(screen.getByText(/\(1\)/)).toBeInTheDocument(); // Others have 1
        });

        it('should handle single relation type', () => {
            render(<RelatedWork resource={mockResourceSingleIdentifier} />);

            expect(screen.getByRole('heading', { name: /citations/i })).toBeInTheDocument();
            expect(screen.queryByRole('heading', { name: /references/i })).not.toBeInTheDocument();
        });
    });

    describe('Priority Ordering', () => {
        it('should order relation types by default priority', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const headings = screen.getAllByRole('heading', { level: 3 });
            const headingTexts = headings.map((h) => h.textContent);

            // Default priority: Cites, References, IsDerivedFrom, IsDocumentedBy
            expect(headingTexts[0]).toMatch(/citations/i);
            expect(headingTexts[1]).toMatch(/references/i);
            expect(headingTexts[2]).toMatch(/derived from/i);
            expect(headingTexts[3]).toMatch(/documentation/i);
        });

        it('should respect custom priority order', () => {
            render(
                <RelatedWork
                    resource={mockResourceWithMultipleTypes}
                    priorityTypes={['IsDocumentedBy', 'IsDerivedFrom', 'References', 'Cites']}
                />,
            );

            const headings = screen.getAllByRole('heading', { level: 3 });
            const headingTexts = headings.map((h) => h.textContent);

            expect(headingTexts[0]).toMatch(/documentation/i);
            expect(headingTexts[1]).toMatch(/derived from/i);
            expect(headingTexts[2]).toMatch(/references/i);
            expect(headingTexts[3]).toMatch(/citations/i);
        });
    });

    describe('Identifier Display', () => {
        it('should display related titles when available', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByText('A Great Scientific Paper')).toBeInTheDocument();
            expect(screen.getByText('Another Citation')).toBeInTheDocument();
            expect(screen.getByText('Referenced Dataset')).toBeInTheDocument();
        });

        it('should display identifier type badges', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const badges = screen.getAllByText('DOI');
            expect(badges.length).toBe(4); // 4 DOIs in mock data

            expect(screen.getByText('URL')).toBeInTheDocument();
        });

        it('should display identifiers', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByText('10.1234/example.citation')).toBeInTheDocument();
            expect(screen.getByText('10.5678/another.paper')).toBeInTheDocument();
            expect(screen.getByText('https://example.com/documentation')).toBeInTheDocument();
        });

        it('should not show title when not provided', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            // ID 4 has no related_title
            const doiLink = screen.getByText('10.1111/source.data');
            const listItem = doiLink.closest('li');
            const titleElement = listItem?.querySelector('.font-medium');

            expect(titleElement).not.toBeInTheDocument();
        });
    });

    describe('Identifier Links', () => {
        it('should create DOI links', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const doiLink = screen.getByText('10.1234/example.citation').closest('a');
            expect(doiLink).toHaveAttribute('href', 'https://doi.org/10.1234/example.citation');
            expect(doiLink).toHaveAttribute('target', '_blank');
            expect(doiLink).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('should handle DOI with doi: prefix', () => {
            render(<RelatedWork resource={mockResourceWithVariousIdentifierTypes} />);

            const doiLink = screen.getByText('doi:10.1234/test').closest('a');
            expect(doiLink).toHaveAttribute('href', 'https://doi.org/10.1234/test');
        });

        it('should create URL links', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const urlLink = screen.getByText('https://example.com/documentation').closest('a');
            expect(urlLink).toHaveAttribute('href', 'https://example.com/documentation');
        });

        it('should create arXiv links', () => {
            render(<RelatedWork resource={mockResourceWithVariousIdentifierTypes} />);

            const arxivLink = screen.getByText('2021.01234').closest('a');
            expect(arxivLink).toHaveAttribute('href', 'https://arxiv.org/abs/2021.01234');
        });

        it('should create PMID links', () => {
            render(<RelatedWork resource={mockResourceWithVariousIdentifierTypes} />);

            const pmidLink = screen.getByText('12345678').closest('a');
            expect(pmidLink).toHaveAttribute('href', 'https://pubmed.ncbi.nlm.nih.gov/12345678');
        });

        it('should create ISBN links', () => {
            render(<RelatedWork resource={mockResourceWithVariousIdentifierTypes} />);

            const isbnLink = screen.getByText('978-3-16-148410-0').closest('a');
            expect(isbnLink).toHaveAttribute('href', 'https://www.worldcat.org/isbn/978-3-16-148410-0');
        });

        it('should create Handle links', () => {
            render(<RelatedWork resource={mockResourceWithVariousIdentifierTypes} />);

            const handleLink = screen.getByText('hdl:1234/5678').closest('a');
            expect(handleLink).toHaveAttribute('href', 'https://hdl.handle.net/1234/5678');
        });
    });

    describe('Max Items Per Type', () => {
        it('should limit items when maxPerType is set', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} maxPerType={1} />);

            // Cites has 2 items, but should only show 1
            const citesItems = screen.getAllByText(/A Great Scientific Paper|Another Citation/);
            expect(citesItems.length).toBe(1);
        });

        it('should show "Showing X of Y" message when limited', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} maxPerType={1} />);

            expect(screen.getByText(/Showing 1 of 2 cites items/i)).toBeInTheDocument();
        });

        it('should not limit items when maxPerType is 0', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} maxPerType={0} />);

            // Should show both Cites items
            expect(screen.getByText('A Great Scientific Paper')).toBeInTheDocument();
            expect(screen.getByText('Another Citation')).toBeInTheDocument();
        });

        it('should not show "Showing X of Y" when all items displayed', () => {
            render(<RelatedWork resource={mockResourceSingleIdentifier} maxPerType={5} />);

            expect(screen.queryByText(/Showing \d+ of \d+ items/i)).not.toBeInTheDocument();
        });
    });

    describe('Relation Type Colors', () => {
        it('should apply blue color to citation types', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const citationIcon = container.querySelector('.text-blue-600');
            expect(citationIcon).toBeInTheDocument();
        });

        it('should apply green color to documentation types', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const docIcon = container.querySelector('.text-green-600');
            expect(docIcon).toBeInTheDocument();
        });

        it('should apply orange color to derivation types', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const derivationIcon = container.querySelector('.text-orange-600');
            expect(derivationIcon).toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('should handle identifiers without IDs using index as key', () => {
            const resourceWithoutIds = {
                related_identifiers: [
                    { identifier: '10.1234/test1', identifier_type: 'DOI', relation_type: 'Cites' },
                    { identifier: '10.1234/test2', identifier_type: 'DOI', relation_type: 'Cites' },
                ],
            };

            render(<RelatedWork resource={resourceWithoutIds} />);

            expect(screen.getByText('10.1234/test1')).toBeInTheDocument();
            expect(screen.getByText('10.1234/test2')).toBeInTheDocument();
        });

        it('should handle very long identifiers', () => {
            const resourceWithLongIdentifier = {
                related_identifiers: [
                    {
                        identifier: '10.1234/very-long-identifier-that-might-wrap-in-the-ui-component',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                    },
                ],
            };

            render(<RelatedWork resource={resourceWithLongIdentifier} />);

            expect(
                screen.getByText('10.1234/very-long-identifier-that-might-wrap-in-the-ui-component'),
            ).toBeInTheDocument();
        });

        it('should handle very long titles', () => {
            const resourceWithLongTitle = {
                related_identifiers: [
                    {
                        identifier: '10.1234/test',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        related_title:
                            'A Very Long Title That Should Wrap Properly In The User Interface Without Breaking Layout',
                    },
                ],
            };

            render(<RelatedWork resource={resourceWithLongTitle} />);

            expect(
                screen.getByText(
                    /A Very Long Title That Should Wrap Properly In The User Interface Without Breaking Layout/,
                ),
            ).toBeInTheDocument();
        });

        it('should handle unknown identifier types gracefully', () => {
            const resourceWithUnknownType = {
                related_identifiers: [
                    {
                        identifier: 'unknown-id-123',
                        identifier_type: 'CUSTOM',
                        relation_type: 'Cites',
                    },
                ],
            };

            render(<RelatedWork resource={resourceWithUnknownType} />);

            expect(screen.getByText('CUSTOM')).toBeInTheDocument();
            expect(screen.getByText('unknown-id-123')).toBeInTheDocument();
        });

        it('should handle unknown relation types gracefully', () => {
            const resourceWithUnknownRelation = {
                related_identifiers: [
                    {
                        identifier: '10.1234/test',
                        identifier_type: 'DOI',
                        relation_type: 'CustomRelationType',
                    },
                ],
            };

            render(<RelatedWork resource={resourceWithUnknownRelation} />);

            expect(screen.getByText('CustomRelationType')).toBeInTheDocument();
        });

        it('should not create link for unknown identifier type', () => {
            const resourceWithUnknownType = {
                related_identifiers: [
                    {
                        identifier: 'custom-id',
                        identifier_type: 'UNKNOWN',
                        relation_type: 'Cites',
                    },
                ],
            };

            render(<RelatedWork resource={resourceWithUnknownType} />);

            const identifier = screen.getByText('custom-id');
            expect(identifier.tagName).not.toBe('A');
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Related Work');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <RelatedWork resource={mockResourceWithMultipleTypes} heading="Related Resources" />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Related Resources');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });

        it('should use semantic HTML list elements', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const lists = document.querySelectorAll('ul');
            expect(lists.length).toBeGreaterThan(0);

            const listItems = document.querySelectorAll('li');
            expect(listItems.length).toBe(5); // 5 related identifiers
        });

        it('should use proper heading hierarchy', () => {
            render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const h2 = screen.getByRole('heading', { name: /^related work$/i, level: 2 });
            expect(h2).toBeInTheDocument();

            const h3s = screen.getAllByRole('heading', { level: 3 });
            expect(h3s.length).toBe(4); // 4 relation types
        });
    });

    describe('Dark Mode Support', () => {
        it('should have dark mode classes for list items', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const listItem = container.querySelector('.dark\\:bg-gray-800');
            expect(listItem).toBeInTheDocument();
        });

        it('should have dark mode classes for badges', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const badge = container.querySelector('.dark\\:bg-gray-700');
            expect(badge).toBeInTheDocument();
        });

        it('should have dark mode classes for text', () => {
            const { container } = render(<RelatedWork resource={mockResourceWithMultipleTypes} />);

            const darkText = container.querySelector('.dark\\:text-gray-100');
            expect(darkText).toBeInTheDocument();
        });
    });
});
