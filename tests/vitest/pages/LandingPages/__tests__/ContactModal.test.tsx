import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ContactModal } from '@/pages/LandingPages/components/ContactModal';

// Mock fetch globally
const mockFetch = vi.fn();
global.fetch = mockFetch;

describe('ContactModal', () => {
    const mockOnClose = vi.fn();

    const defaultContactPerson = {
        id: 1,
        name: 'Dr. John Smith',
        given_name: 'John',
        family_name: 'Smith',
        type: 'person',
        has_email: true,
    };

    const secondContactPerson = {
        id: 2,
        name: 'Dr. Jane Doe',
        given_name: 'Jane',
        family_name: 'Doe',
        type: 'person',
        has_email: true,
    };

    const defaultProps = {
        isOpen: true,
        onClose: mockOnClose,
        selectedPerson: defaultContactPerson,
        contactPersons: [defaultContactPerson, secondContactPerson],
        datasetTitle: 'Test Dataset 2024',
    };

    beforeEach(() => {
        vi.clearAllMocks();
        // Setup CSRF meta tag
        document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-token">';
        // Mock window.location
        Object.defineProperty(window, 'location', {
            value: { pathname: '/10.5880/test.2024.001/test-dataset' },
            writable: true,
        });
    });

    describe('rendering', () => {
        it('renders the modal when open', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByRole('dialog')).toBeInTheDocument();
            expect(screen.getByText('Contact Request')).toBeInTheDocument();
        });

        it('does not render when closed', () => {
            render(<ContactModal {...defaultProps} isOpen={false} />);

            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });

        it('displays the dataset title in description', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByText('Test Dataset 2024')).toBeInTheDocument();
        });
    });

    describe('form fields', () => {
        it('renders sender name input', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByLabelText(/your name/i)).toBeInTheDocument();
        });

        it('renders sender email input', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByLabelText(/your email/i)).toBeInTheDocument();
        });

        it('renders message textarea', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByRole('textbox', { name: /message/i })).toBeInTheDocument();
        });

        it('renders copy to sender checkbox', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByLabelText(/send me a copy/i)).toBeInTheDocument();
        });

        it('renders cancel and submit buttons', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /send message/i })).toBeInTheDocument();
        });
    });

    describe('recipient selection', () => {
        it('shows recipient selection when multiple contacts and person selected', () => {
            render(<ContactModal {...defaultProps} />);

            expect(screen.getByText(/dr\. john smith only/i)).toBeInTheDocument();
            expect(screen.getByText(/all contact persons \(2\)/i)).toBeInTheDocument();
        });

        it('shows single recipient when only one contact person', () => {
            render(
                <ContactModal
                    {...defaultProps}
                    contactPersons={[defaultContactPerson]}
                    selectedPerson={defaultContactPerson}
                />,
            );

            expect(screen.getByText(/to:/i)).toBeInTheDocument();
            expect(screen.getByText('Dr. John Smith')).toBeInTheDocument();
        });

        it('shows "all" recipient when selectedPerson is null', () => {
            render(<ContactModal {...defaultProps} selectedPerson={null} />);

            expect(screen.getByText(/all contact persons \(2\)/i)).toBeInTheDocument();
        });
    });

    describe('form input', () => {
        it('allows typing in name field', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');

            expect(screen.getByLabelText(/your name/i)).toHaveValue('Test User');
        });

        it('allows typing in email field', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');

            expect(screen.getByLabelText(/your email/i)).toHaveValue('test@example.com');
        });

        it('allows typing in message field', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a test message');

            expect(screen.getByRole('textbox', { name: /message/i })).toHaveValue('This is a test message');
        });

        it('allows toggling copy to sender checkbox', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            const checkbox = screen.getByLabelText(/send me a copy/i);
            expect(checkbox).not.toBeChecked();

            await user.click(checkbox);

            expect(checkbox).toBeChecked();
        });

        it('allows switching recipient selection', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            const allRadio = screen.getByRole('radio', { name: /all contact persons/i });
            await user.click(allRadio);

            expect(allRadio).toBeChecked();
        });
    });

    describe('validation', () => {
        it('shows error when name is only whitespace', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            // Use whitespace-only to bypass HTML5 required but trigger JS validation
            await user.type(screen.getByLabelText(/your name/i), '   ');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a test message for the form');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            expect(screen.getByText('Please enter your name.')).toBeInTheDocument();
        });

        it('has HTML5 email validation on email field', async () => {
            render(<ContactModal {...defaultProps} />);

            const emailInput = screen.getByLabelText(/your email/i);
            expect(emailInput).toHaveAttribute('type', 'email');
            expect(emailInput).toHaveAttribute('required');
        });

        it('shows error when message is too short', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'Short');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            expect(screen.getByText('Please enter a message (at least 10 characters).')).toBeInTheDocument();
        });
    });

    describe('form submission', () => {
        it('sends form data to correct endpoint', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({ ok: true });

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                expect(mockFetch).toHaveBeenCalledWith(
                    '/10.5880/test.2024.001/test-dataset/contact',
                    expect.objectContaining({
                        method: 'POST',
                        headers: expect.objectContaining({
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': 'test-csrf-token',
                        }),
                    }),
                );
            });
        });

        it('shows loading state while submitting', async () => {
            const user = userEvent.setup();
            mockFetch.mockImplementation(() => new Promise(() => {})); // Never resolves

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            expect(screen.getByText(/sending\.\.\./i)).toBeInTheDocument();
        });

        it('disables form fields while submitting', async () => {
            const user = userEvent.setup();
            mockFetch.mockImplementation(() => new Promise(() => {}));

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            expect(screen.getByLabelText(/your name/i)).toBeDisabled();
            expect(screen.getByLabelText(/your email/i)).toBeDisabled();
            expect(screen.getByRole('textbox', { name: /message/i })).toBeDisabled();
        });

        it('shows success message after successful submission', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({ ok: true });

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                expect(screen.getByText(/message sent successfully/i)).toBeInTheDocument();
            });
        });

        it('shows error message on API failure', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({
                ok: false,
                json: () => Promise.resolve({ message: 'Server error occurred' }),
            });

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                expect(screen.getByText('Server error occurred')).toBeInTheDocument();
            });
        });

        it('shows default error on API failure without message', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({
                ok: false,
                json: () => Promise.resolve({}),
            });

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                expect(screen.getByText(/failed to send message/i)).toBeInTheDocument();
            });
        });

        it('shows network error on fetch failure', async () => {
            const user = userEvent.setup();
            mockFetch.mockRejectedValueOnce(new Error('Network error'));

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                expect(screen.getByText(/network error/i)).toBeInTheDocument();
            });
        });
    });

    describe('cancel behavior', () => {
        it('calls onClose when cancel button is clicked', async () => {
            const user = userEvent.setup();
            render(<ContactModal {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /cancel/i }));

            expect(mockOnClose).toHaveBeenCalled();
        });

        it('does not allow closing while submitting', async () => {
            const user = userEvent.setup();
            mockFetch.mockImplementation(() => new Promise(() => {}));

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            expect(screen.getByRole('button', { name: /cancel/i })).toBeDisabled();
        });
    });

    describe('form data', () => {
        it('sends correct data for single recipient', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({ ok: true });

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                const callBody = JSON.parse(mockFetch.mock.calls[0][1].body);
                expect(callBody).toEqual(
                    expect.objectContaining({
                        sender_name: 'Test User',
                        sender_email: 'test@example.com',
                        message: 'This is a valid test message',
                        send_to_all: false,
                        resource_creator_id: 1,
                    }),
                );
            });
        });

        it('sends correct data for all recipients', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({ ok: true });

            render(<ContactModal {...defaultProps} />);

            // Switch to all recipients
            await user.click(screen.getByRole('radio', { name: /all contact persons/i }));
            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                const callBody = JSON.parse(mockFetch.mock.calls[0][1].body);
                expect(callBody.send_to_all).toBe(true);
                expect(callBody.resource_creator_id).toBeNull();
            });
        });

        it('includes copy_to_sender when checkbox is checked', async () => {
            const user = userEvent.setup();
            mockFetch.mockResolvedValueOnce({ ok: true });

            render(<ContactModal {...defaultProps} />);

            await user.type(screen.getByLabelText(/your name/i), 'Test User');
            await user.type(screen.getByLabelText(/your email/i), 'test@example.com');
            await user.type(screen.getByRole('textbox', { name: /message/i }), 'This is a valid test message');
            await user.click(screen.getByLabelText(/send me a copy/i));
            await user.click(screen.getByRole('button', { name: /send message/i }));

            await waitFor(() => {
                const callBody = JSON.parse(mockFetch.mock.calls[0][1].body);
                expect(callBody.copy_to_sender).toBe(true);
            });
        });
    });
});
