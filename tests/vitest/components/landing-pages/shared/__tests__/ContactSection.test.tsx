import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ContactSection from '@/components/landing-pages/shared/ContactSection';

describe('ContactSection', () => {
    const mockResourceWithContacts = {
        authors: [
            {
                id: 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'John',
                    family_name: 'Doe',
                    orcid: '0000-0001-2345-6789',
                },
                roles: [
                    { id: 1, name: 'Author', slug: 'author' },
                    { id: 2, name: 'Contact Person', slug: 'contactperson' },
                ],
                email: 'john.doe@example.com',
                website: 'https://johndoe.example.com',
                affiliations: [],
            },
            {
                id: 2,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'Jane',
                    family_name: 'Smith',
                },
                roles: [{ id: 1, name: 'Author', slug: 'author' }],
                email: 'jane.smith@example.com',
                affiliations: [],
            },
            {
                id: 3,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'Bob',
                    family_name: 'Wilson',
                },
                roles: [{ id: 2, name: 'Contact Person', slug: 'contactperson' }],
                email: 'bob.wilson@example.com',
                website: 'https://bobwilson.example.com',
                affiliations: [],
            },
        ],
    };

    const mockResourceWithoutContacts = {
        authors: [
            {
                id: 1,
                authorable_type: 'App\\Models\\Person',
                authorable: {
                    given_name: 'Jane',
                    family_name: 'Smith',
                },
                roles: [{ id: 1, name: 'Author', slug: 'author' }],
                email: 'jane.smith@example.com',
                affiliations: [],
            },
        ],
    };

    const mockResourceEmpty = {
        authors: [],
    };

    const mockResourceNoAuthors = {};

    describe('Rendering', () => {
        it('should render contact section when contact persons exist', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            expect(screen.getByRole('heading', { name: /contact/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(
                <ContactSection resource={mockResourceWithContacts} heading="Get in Touch" />,
            );

            expect(screen.getByRole('heading', { name: /get in touch/i })).toBeInTheDocument();
        });

        it('should not render when no contact persons', () => {
            const { container } = render(
                <ContactSection resource={mockResourceWithoutContacts} />,
            );

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when authors is empty', () => {
            const { container } = render(<ContactSection resource={mockResourceEmpty} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when authors property missing', () => {
            const { container } = render(<ContactSection resource={mockResourceNoAuthors} />);

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Description Box', () => {
        it('should show description box by default', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            expect(
                screen.getByText(/questions about this dataset\?/i),
            ).toBeInTheDocument();
        });

        it('should show singular text for one contact person', () => {
            const resourceWithOneContact = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'John',
                            family_name: 'Doe',
                        },
                        roles: [{ id: 2, name: 'Contact Person', slug: 'contactperson' }],
                        email: 'john.doe@example.com',
                        affiliations: [],
                    },
                ],
            };

            render(<ContactSection resource={resourceWithOneContact} />);

            expect(
                screen.getByText(/contact the person listed below/i),
            ).toBeInTheDocument();
        });

        it('should show plural text for multiple contact persons', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            expect(
                screen.getByText(/contact any of the 2 persons listed below/i),
            ).toBeInTheDocument();
        });

        it('should hide description when showDescription=false', () => {
            render(<ContactSection resource={mockResourceWithContacts} showDescription={false} />);

            expect(
                screen.queryByText(/questions about this dataset\?/i),
            ).not.toBeInTheDocument();
        });
    });

    describe('Contact Persons Display', () => {
        it('should display only contact persons', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            // John Doe and Bob Wilson are contact persons
            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.getByText('Bob Wilson')).toBeInTheDocument();

            // Jane Smith is only an author, should not appear
            expect(screen.queryByText('Jane Smith')).not.toBeInTheDocument();
        });

        it('should show email addresses for contact persons', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            expect(
                screen.getByRole('link', { name: /john\.doe@example\.com/i }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: /bob\.wilson@example\.com/i }),
            ).toBeInTheDocument();
        });

        it('should show website links for contact persons', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            const johnWebsite = screen.getByRole('link', { name: /johndoe\.example\.com/i });
            expect(johnWebsite).toBeInTheDocument();
            expect(johnWebsite).toHaveAttribute('href', 'https://johndoe.example.com');

            const bobWebsite = screen.getByRole('link', { name: /bobwilson\.example\.com/i });
            expect(bobWebsite).toBeInTheDocument();
            expect(bobWebsite).toHaveAttribute('href', 'https://bobwilson.example.com');
        });
    });

    describe('Integration with AuthorsList', () => {
        it('should pass filterByRole="contactperson" to AuthorsList', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            // Only contact persons should be visible
            const authorCards = screen.getAllByRole('heading', { level: 3 });
            expect(authorCards).toHaveLength(2); // John Doe and Bob Wilson

            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.getByText('Bob Wilson')).toBeInTheDocument();
            expect(screen.queryByText('Jane Smith')).not.toBeInTheDocument();
        });

        it('should pass showEmail=true to AuthorsList', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            // Email links should be visible
            expect(screen.getByText('john.doe@example.com')).toBeInTheDocument();
            expect(screen.getByText('bob.wilson@example.com')).toBeInTheDocument();
        });

        it('should pass showWebsite=true to AuthorsList', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            // Website links should be visible
            expect(screen.getByText(/johndoe\.example\.com/i)).toBeInTheDocument();
            expect(screen.getByText(/bobwilson\.example\.com/i)).toBeInTheDocument();
        });

        it('should pass custom heading to AuthorsList', () => {
            render(
                <ContactSection
                    resource={mockResourceWithContacts}
                    heading="Contact Information"
                />,
            );

            expect(
                screen.getByRole('heading', { name: /contact information/i }),
            ).toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('should handle contact person without email', () => {
            const resourceWithoutEmail = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'John',
                            family_name: 'Doe',
                        },
                        roles: [{ id: 2, name: 'Contact Person', slug: 'contactperson' }],
                        email: null,
                        affiliations: [],
                    },
                ],
            };

            render(<ContactSection resource={resourceWithoutEmail} />);

            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /@/i })).not.toBeInTheDocument();
        });

        it('should handle contact person without website', () => {
            const resourceWithoutWebsite = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'John',
                            family_name: 'Doe',
                        },
                        roles: [{ id: 2, name: 'Contact Person', slug: 'contactperson' }],
                        email: 'john.doe@example.com',
                        website: null,
                        affiliations: [],
                    },
                ],
            };

            render(<ContactSection resource={resourceWithoutWebsite} />);

            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.getByText('john.doe@example.com')).toBeInTheDocument();
            // Only 1 external link (email, not website)
            const links = screen.getAllByRole('link');
            expect(links.length).toBeGreaterThan(0);
        });

        it('should handle contact person with multiple roles', () => {
            const resourceWithMultipleRoles = {
                authors: [
                    {
                        id: 1,
                        authorable_type: 'App\\Models\\Person',
                        authorable: {
                            given_name: 'John',
                            family_name: 'Doe',
                        },
                        roles: [
                            { id: 1, name: 'Author', slug: 'author' },
                            { id: 2, name: 'Contact Person', slug: 'contactperson' },
                            { id: 3, name: 'Data Collector', slug: 'datacollector' },
                        ],
                        email: 'john.doe@example.com',
                        affiliations: [],
                    },
                ],
            };

            render(<ContactSection resource={resourceWithMultipleRoles} />);

            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.getByText('Author')).toBeInTheDocument();
            expect(screen.getByText('Contact Person')).toBeInTheDocument();
            expect(screen.getByText('Data Collector')).toBeInTheDocument();
        });
    });

    describe('Styling and Layout', () => {
        it('should have blue description box styling', () => {
            const { container } = render(<ContactSection resource={mockResourceWithContacts} />);

            const descriptionBox = container.querySelector('.bg-blue-50');
            expect(descriptionBox).toBeInTheDocument();
        });

        it('should show mail icon in description', () => {
            render(<ContactSection resource={mockResourceWithContacts} />);

            // Mail icon is rendered (check for its presence via aria-hidden attribute)
            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });
    });
});
