import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import AuthorsList from '@/components/landing-pages/shared/AuthorsList';

describe('AuthorsList', () => {
    const mockResourceWithAuthors = {
        authors: [
            {
                id: 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'John',
                    family_name: 'Doe',
                    orcid: '0000-0001-2345-6789',
                },
                affiliations: [
                    {
                        id: 1,
                        organization_name: 'GFZ German Research Centre for Geosciences',
                        ror_id: '04z8jg394',
                    },
                ],
                roles: [
                    { id: 1, name: 'Author', slug: 'author' },
                    { id: 2, name: 'Contact Person', slug: 'contactperson' },
                ],
                position: 'Senior Researcher',
                email: 'john.doe@example.com',
                website: 'https://johndoe.example.com',
            },
            {
                id: 2,
                authorable_type: 'App\\Models\\Institution',
                authorable: {
                    name: 'Max Planck Institute',
                },
                affiliations: [],
                roles: [{ id: 3, name: 'Contributor', slug: 'contributor' }],
            },
        ],
    };

    const mockResourceEmpty = {
        authors: [],
    };

    const mockResourceNoAuthors = {};

    describe('Rendering', () => {
        it('should render authors list with heading', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(screen.getByRole('heading', { name: /authors/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} heading="Contributors" />);

            expect(screen.getByRole('heading', { name: /contributors/i })).toBeInTheDocument();
        });

        it('should not render when no authors', () => {
            const { container } = render(<AuthorsList resource={mockResourceEmpty} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when authors property missing', () => {
            const { container } = render(<AuthorsList resource={mockResourceNoAuthors} />);

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Person Authors', () => {
        it('should display person name', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(screen.getByText('John Doe')).toBeInTheDocument();
        });

        it('should display ORCID link', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            const orcidLink = screen.getByRole('link', { name: /0000-0001-2345-6789/i });
            expect(orcidLink).toHaveAttribute(
                'href',
                'https://orcid.org/0000-0001-2345-6789',
            );
            expect(orcidLink).toHaveAttribute('target', '_blank');
            expect(orcidLink).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('should display position', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(screen.getByText('Senior Researcher')).toBeInTheDocument();
        });

        it('should display roles as badges', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(screen.getByText('Author')).toBeInTheDocument();
            expect(screen.getByText('Contact Person')).toBeInTheDocument();
        });

        it('should not show email by default', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(screen.queryByText('john.doe@example.com')).not.toBeInTheDocument();
        });

        it('should show email when showEmail=true', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} showEmail />);

            const emailLink = screen.getByRole('link', { name: /john\.doe@example\.com/i });
            expect(emailLink).toBeInTheDocument();
            expect(emailLink).toHaveAttribute('href', 'mailto:john.doe@example.com');
        });

        it('should not show website by default', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(
                screen.queryByRole('link', { name: /johndoe\.example\.com/i }),
            ).not.toBeInTheDocument();
        });

        it('should show website when showWebsite=true', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} showWebsite />);

            const websiteLink = screen.getByRole('link', { name: /johndoe\.example\.com/i });
            expect(websiteLink).toBeInTheDocument();
            expect(websiteLink).toHaveAttribute('href', 'https://johndoe.example.com');
            expect(websiteLink).toHaveAttribute('target', '_blank');
        });
    });

    describe('Institution Authors', () => {
        it('should display institution name', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(screen.getByText('Max Planck Institute')).toBeInTheDocument();
        });

        it('should not display ORCID for institutions', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            // Only 1 ORCID link (from person, not institution)
            const orcidLinks = screen.getAllByRole('link', { name: /orcid/i });
            expect(orcidLinks).toHaveLength(1);
        });
    });

    describe('Affiliations', () => {
        it('should display affiliation name', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            expect(
                screen.getByText('GFZ German Research Centre for Geosciences'),
            ).toBeInTheDocument();
        });

        it('should display ROR link when ror_id exists', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            const rorLink = screen.getByRole('link', {
                name: /gfz german research centre for geosciences/i,
            });
            expect(rorLink).toHaveAttribute('href', 'https://ror.org/04z8jg394');
            expect(rorLink).toHaveAttribute('target', '_blank');
        });

        it('should display affiliation without link when no ror_id', () => {
            const resourceWithoutRor = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'Jane',
                            family_name: 'Smith',
                        },
                        affiliations: [
                            {
                                id: 1,
                                organization_name: 'Local University',
                                ror_id: null,
                            },
                        ],
                        roles: [],
                    },
                ],
            };

            render(<AuthorsList resource={resourceWithoutRor} />);

            expect(screen.getByText('Local University')).toBeInTheDocument();
            expect(
                screen.queryByRole('link', { name: /local university/i }),
            ).not.toBeInTheDocument();
        });
    });

    describe('Filtering', () => {
        it('should filter authors by role', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} filterByRole="author" />);

            // John Doe has "Author" role
            expect(screen.getByText('John Doe')).toBeInTheDocument();

            // Max Planck Institute only has "Contributor" role, should not appear
            expect(screen.queryByText('Max Planck Institute')).not.toBeInTheDocument();
        });

        it('should return null when no authors match filter', () => {
            const { container } = render(
                <AuthorsList resource={mockResourceWithAuthors} filterByRole="nonexistent" />,
            );

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Max Authors Limit', () => {
        const resourceWithManyAuthors = {
            authors: Array.from({ length: 5 }, (_, i) => ({
                id: i + 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: `Author${i + 1}`,
                    family_name: `Last${i + 1}`,
                },
                affiliations: [],
                roles: [],
            })),
        };

        it('should show all authors when maxAuthors=0', () => {
            render(<AuthorsList resource={resourceWithManyAuthors} />);

            expect(screen.getByText('Author1 Last1')).toBeInTheDocument();
            expect(screen.getByText('Author5 Last5')).toBeInTheDocument();
        });

        it('should limit authors when maxAuthors is set', () => {
            render(<AuthorsList resource={resourceWithManyAuthors} maxAuthors={2} />);

            expect(screen.getByText('Author1 Last1')).toBeInTheDocument();
            expect(screen.getByText('Author2 Last2')).toBeInTheDocument();
            expect(screen.queryByText('Author3 Last3')).not.toBeInTheDocument();
        });

        it('should show indicator when authors are limited', () => {
            render(<AuthorsList resource={resourceWithManyAuthors} maxAuthors={2} />);

            expect(screen.getByText(/showing 2 of 5 authors/i)).toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('should handle author without given name', () => {
            const resourceWithoutGivenName = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            family_name: 'Doe',
                        },
                        affiliations: [],
                        roles: [],
                    },
                ],
            };

            render(<AuthorsList resource={resourceWithoutGivenName} />);

            expect(screen.getByText('Doe')).toBeInTheDocument();
        });

        it('should handle author without family name', () => {
            const resourceWithoutFamilyName = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'John',
                        },
                        affiliations: [],
                        roles: [],
                    },
                ],
            };

            render(<AuthorsList resource={resourceWithoutFamilyName} />);

            expect(screen.getByText('John')).toBeInTheDocument();
        });

        it('should show "Unknown Author" when no name data', () => {
            const resourceWithoutName = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {},
                        affiliations: [],
                        roles: [],
                    },
                ],
            };

            render(<AuthorsList resource={resourceWithoutName} />);

            expect(screen.getByText('Unknown Author')).toBeInTheDocument();
        });

        it('should handle author without ORCID', () => {
            const resourceWithoutOrcid = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'Jane',
                            family_name: 'Smith',
                            orcid: null,
                        },
                        affiliations: [],
                        roles: [],
                    },
                ],
            };

            render(<AuthorsList resource={resourceWithoutOrcid} />);

            expect(screen.getByText('Jane Smith')).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /orcid/i })).not.toBeInTheDocument();
        });

        it('should handle author without position', () => {
            const resourceWithoutPosition = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'John',
                            family_name: 'Doe',
                        },
                        affiliations: [],
                        roles: [],
                        position: null,
                    },
                ],
            };

            render(<AuthorsList resource={resourceWithoutPosition} />);

            expect(screen.getByText('John Doe')).toBeInTheDocument();
            // Position text should not exist
            expect(screen.queryByText(/researcher/i)).not.toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(<AuthorsList resource={mockResourceWithAuthors} />);

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Authors');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <AuthorsList resource={mockResourceWithAuthors} heading="Contributors" />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Contributors');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(<AuthorsList resource={mockResourceWithAuthors} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });
    });
});
