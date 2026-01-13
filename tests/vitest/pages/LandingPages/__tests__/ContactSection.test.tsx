/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ContactSection } from '@/pages/LandingPages/components/ContactSection';

// Mock ContactModal to avoid complex dependencies
vi.mock('@/pages/LandingPages/components/ContactModal', () => ({
    ContactModal: ({ isOpen, onClose, selectedPerson, contactPersons, datasetTitle }: {
        isOpen: boolean;
        onClose: () => void;
        selectedPerson: { name: string } | null;
        contactPersons: Array<{ name: string }>;
        datasetTitle: string;
    }) => {
        if (!isOpen) return null;
        return (
            <div data-testid="contact-modal">
                <button onClick={onClose}>Close</button>
                <span data-testid="selected-person">{selectedPerson?.name || 'All'}</span>
                <span data-testid="contact-count">{contactPersons.length}</span>
                <span data-testid="dataset-title">{datasetTitle}</span>
            </div>
        );
    },
}));

// Mock data factories
const createAffiliation = (overrides: Partial<{ name: string; identifier: string | null; scheme: string | null }> = {}) => ({
    name: 'GFZ Potsdam',
    identifier: null,
    scheme: null,
    ...overrides,
});

const createContactPerson = (overrides: Partial<{
    id: number;
    name: string;
    given_name: string | null;
    family_name: string | null;
    type: string;
    affiliations: Array<{ name: string; identifier: string | null; scheme: string | null }>;
    orcid: string | null;
    website: string | null;
    has_email: boolean;
}> = {}) => ({
    id: 1,
    name: 'John Doe',
    given_name: 'John',
    family_name: 'Doe',
    type: 'Person',
    affiliations: [],
    orcid: null,
    website: null,
    has_email: true,
    ...overrides,
});

const defaultProps = {
    contactPersons: [createContactPerson()],
    datasetTitle: 'Test Dataset',
};

describe('ContactSection', () => {
    describe('rendering conditions', () => {
        it('renders when contact persons exist', () => {
            render(<ContactSection {...defaultProps} />);
            
            expect(screen.getByText('Contact Information')).toBeInTheDocument();
        });

        it('returns null when contact persons array is empty', () => {
            const { container } = render(
                <ContactSection contactPersons={[]} datasetTitle="Test Dataset" />
            );
            
            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('contact person display', () => {
        it('displays contact person name as button when has_email is true', () => {
            render(<ContactSection {...defaultProps} />);
            
            const contactButton = screen.getByRole('button', { name: /John Doe/i });
            expect(contactButton).toBeInTheDocument();
        });

        it('does not display contact button when has_email is false', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({ has_email: false })]}
                />
            );
            
            expect(screen.queryByRole('button', { name: /John Doe/i })).not.toBeInTheDocument();
        });

        it('displays multiple contact persons', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[
                        createContactPerson({ id: 1, name: 'John Doe' }),
                        createContactPerson({ id: 2, name: 'Jane Smith' }),
                    ]}
                />
            );
            
            expect(screen.getByRole('button', { name: /John Doe/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /Jane Smith/i })).toBeInTheDocument();
        });
    });

    describe('ORCID link', () => {
        it('renders ORCID link when person has ORCID', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({ orcid: '0000-0002-1825-0097' })]}
                />
            );
            
            // accessible name comes from img alt="ORCID"
            const orcidLink = screen.getByRole('link', { name: /^ORCID$/i });
            expect(orcidLink).toHaveAttribute('href', 'https://orcid.org/0000-0002-1825-0097');
            expect(orcidLink).toHaveAttribute('target', '_blank');
            expect(orcidLink).toHaveAttribute('title', 'ORCID Profile');
        });

        it('does not render ORCID link when person has no ORCID', () => {
            render(<ContactSection {...defaultProps} />);
            
            expect(screen.queryByRole('link', { name: /^ORCID$/i })).not.toBeInTheDocument();
        });
    });

    describe('website link', () => {
        it('renders website link when person has website', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({ website: 'https://example.com/john' })]}
                />
            );
            
            const websiteLink = screen.getByRole('link', { name: /Website/i });
            expect(websiteLink).toHaveAttribute('href', 'https://example.com/john');
            expect(websiteLink).toHaveAttribute('target', '_blank');
        });

        it('does not render website link when person has no website', () => {
            render(<ContactSection {...defaultProps} />);
            
            expect(screen.queryByRole('link', { name: /Website/i })).not.toBeInTheDocument();
        });
    });

    describe('affiliations', () => {
        it('renders affiliations when present', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({
                        affiliations: [createAffiliation()],
                    })]}
                />
            );
            
            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        });

        it('renders multiple affiliations with comma separator', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({
                        affiliations: [
                            createAffiliation({ name: 'GFZ Potsdam' }),
                            createAffiliation({ name: 'TU Berlin' }),
                        ],
                    })]}
                />
            );
            
            expect(screen.getByText(/GFZ Potsdam/)).toBeInTheDocument();
            expect(screen.getByText(/TU Berlin/)).toBeInTheDocument();
        });

        it('renders ROR link for affiliation with ROR identifier', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({
                        affiliations: [createAffiliation({
                            identifier: 'https://ror.org/04z8jg394',
                            scheme: 'ROR',
                        })],
                    })]}
                />
            );
            
            // accessible name comes from img alt="ROR"
            const rorLink = screen.getByRole('link', { name: /^ROR$/i });
            expect(rorLink).toHaveAttribute('href', 'https://ror.org/04z8jg394');
            expect(rorLink).toHaveAttribute('title', 'ROR Profile');
        });

        it('does not render ROR link when scheme is not ROR', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[createContactPerson({
                        affiliations: [createAffiliation({
                            identifier: 'some-id',
                            scheme: 'ISNI',
                        })],
                    })]}
                />
            );
            
            expect(screen.queryByRole('link', { name: /^ROR$/i })).not.toBeInTheDocument();
        });
    });

    describe('contact modal interaction', () => {
        it('opens modal when clicking on contact person', () => {
            render(<ContactSection {...defaultProps} />);
            
            const contactButton = screen.getByRole('button', { name: /John Doe/i });
            fireEvent.click(contactButton);
            
            expect(screen.getByTestId('contact-modal')).toBeInTheDocument();
            expect(screen.getByTestId('selected-person')).toHaveTextContent('John Doe');
        });

        it('closes modal when clicking close button', () => {
            render(<ContactSection {...defaultProps} />);
            
            // Open modal
            fireEvent.click(screen.getByRole('button', { name: /John Doe/i }));
            expect(screen.getByTestId('contact-modal')).toBeInTheDocument();
            
            // Close modal
            fireEvent.click(screen.getByRole('button', { name: /Close/i }));
            expect(screen.queryByTestId('contact-modal')).not.toBeInTheDocument();
        });

        it('passes correct dataset title to modal', () => {
            render(<ContactSection {...defaultProps} datasetTitle="My Research Data" />);
            
            fireEvent.click(screen.getByRole('button', { name: /John Doe/i }));
            
            expect(screen.getByTestId('dataset-title')).toHaveTextContent('My Research Data');
        });
    });

    describe('contact all button', () => {
        it('renders "Contact all" button when multiple contact persons exist', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[
                        createContactPerson({ id: 1, name: 'John Doe' }),
                        createContactPerson({ id: 2, name: 'Jane Smith' }),
                    ]}
                />
            );
            
            expect(screen.getByRole('button', { name: /Contact all \(2\)/i })).toBeInTheDocument();
        });

        it('does not render "Contact all" button when only one contact person', () => {
            render(<ContactSection {...defaultProps} />);
            
            expect(screen.queryByRole('button', { name: /Contact all/i })).not.toBeInTheDocument();
        });

        it('opens modal with no selected person when clicking "Contact all"', () => {
            render(
                <ContactSection
                    {...defaultProps}
                    contactPersons={[
                        createContactPerson({ id: 1, name: 'John Doe' }),
                        createContactPerson({ id: 2, name: 'Jane Smith' }),
                    ]}
                />
            );
            
            fireEvent.click(screen.getByRole('button', { name: /Contact all/i }));
            
            expect(screen.getByTestId('contact-modal')).toBeInTheDocument();
            expect(screen.getByTestId('selected-person')).toHaveTextContent('All');
            expect(screen.getByTestId('contact-count')).toHaveTextContent('2');
        });
    });
});
