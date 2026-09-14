/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';

import {
    DATA_REQUEST_HEADING,
    DATA_REQUEST_NO_RECIPIENT_MESSAGE,
    DATA_REQUEST_UNAVAILABLE_HEADING,
    DataRequestSection,
} from '@/pages/LandingPages/components/DataRequestSection';

const contactPersons = [
    {
        id: 1,
        name: 'John Doe',
        given_name: 'John',
        family_name: 'Doe',
        type: 'Person',
        source: 'creator' as const,
        affiliations: [],
        orcid: null,
        website: null,
        has_email: true,
    },
    {
        id: 2,
        name: 'Jane Doe',
        given_name: 'Jane',
        family_name: 'Doe',
        type: 'Person',
        source: 'contributor' as const,
        affiliations: [],
        orcid: null,
        website: null,
        has_email: true,
    },
];

describe('DataRequestSection', () => {
    beforeEach(() => {
        Object.defineProperty(window, 'location', {
            value: { pathname: '/10.5880/test.001/test-dataset' },
            writable: true,
        });
    });

    it('renders the required accessible heading and no Files heading', () => {
        render(<DataRequestSection hasDataPublicationTeamRecipient />);

        expect(screen.getByRole('heading', { name: DATA_REQUEST_HEADING })).toBeInTheDocument();
        expect(screen.getByTestId('data-request-section')).toHaveAttribute('aria-labelledby', 'heading-data-request');
        expect(screen.queryByRole('heading', { name: 'Files' })).not.toBeInTheDocument();
    });

    it('opens a request modal addressed to the team and all contacts', async () => {
        const user = userEvent.setup();
        render(<DataRequestSection contactPersons={contactPersons} datasetTitle="Test Dataset" hasDataPublicationTeamRecipient />);

        await user.click(screen.getByRole('button', { name: /request data via contact form/i }));

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Test Dataset')).toBeInTheDocument();
        expect(screen.getByText('Data publication team and all contact persons (2)')).toBeInTheDocument();
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
    });

    it('keeps the team-only request available when no contact person has an email', async () => {
        const user = userEvent.setup();
        render(<DataRequestSection contactPersons={[]} hasDataPublicationTeamRecipient />);

        await user.click(screen.getByRole('button', { name: /request data via contact form/i }));

        expect(screen.getByText('Data publication team')).toBeInTheDocument();
        expect(screen.queryByText(/all contact persons \(0\)/i)).not.toBeInTheDocument();
    });

    it('addresses only eligible contact persons when the team recipient is unavailable', async () => {
        const user = userEvent.setup();
        render(<DataRequestSection contactPersons={contactPersons} datasetTitle="Test Dataset" />);

        await user.click(screen.getByRole('button', { name: /request data via contact form/i }));

        expect(screen.getByText('All contact persons (2)')).toBeInTheDocument();
        expect(screen.queryByText(/data publication team/i)).not.toBeInTheDocument();
    });

    it('does not offer a request action when neither contacts nor the team are available', () => {
        render(<DataRequestSection />);

        expect(screen.getByRole('heading', { name: DATA_REQUEST_UNAVAILABLE_HEADING })).toBeInTheDocument();
        expect(screen.getByText(DATA_REQUEST_NO_RECIPIENT_MESSAGE)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /request data via contact form/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('ignores contacts without an email when determining recipient availability', () => {
        render(<DataRequestSection contactPersons={[{ ...contactPersons[0], has_email: false }]} />);

        expect(screen.getByText(DATA_REQUEST_NO_RECIPIENT_MESSAGE)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /request data via contact form/i })).not.toBeInTheDocument();
    });
});
