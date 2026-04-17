import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { LandingPageTemplateConfig } from '@/types/landing-page';

// ─── Mocks ───────────────────────────────────────────────────────────────────

const routerMock = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('axios', () => {
    const post = vi.fn();
    const put = vi.fn();
    const deleteMethod = vi.fn();
    const isAxiosError = vi.fn((value: unknown): value is { isAxiosError: true } => {
        return typeof value === 'object' && value !== null && (value as { isAxiosError?: boolean }).isAxiosError === true;
    });
    return { default: { post, put, delete: deleteMethod, isAxiosError }, post, put, delete: deleteMethod, isAxiosError };
});

const mockedAxiosPost = axios.post as Mock;
const mockedAxiosPut = axios.put as Mock;
const mockedAxiosDelete = axios.delete as Mock;

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({
        props: {
            auth: { user: { can_manage_landing_page_templates: true } },
            templates: mockTemplates,
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

// DnD mocks - simplify to avoid complex sensor setup
vi.mock('@dnd-kit/core', () => ({
    closestCenter: vi.fn(),
    DndContext: ({ children }: { children: React.ReactNode }) => <div data-testid="dnd-context">{children}</div>,
    KeyboardSensor: vi.fn(),
    PointerSensor: vi.fn(),
    useSensor: vi.fn(),
    useSensors: vi.fn(() => []),
}));

vi.mock('@dnd-kit/sortable', () => ({
    arrayMove: vi.fn((arr: string[], from: number, to: number) => {
        const result = [...arr];
        const [item] = result.splice(from, 1);
        result.splice(to, 0, item);
        return result;
    }),
    SortableContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    sortableKeyboardCoordinates: vi.fn(),
    useSortable: vi.fn(() => ({
        attributes: {},
        listeners: {},
        setNodeRef: vi.fn(),
        transform: null,
        transition: null,
        isDragging: false,
    })),
    verticalListSortingStrategy: vi.fn(),
}));

vi.mock('@dnd-kit/utilities', () => ({
    CSS: { Transform: { toString: vi.fn(() => '') } },
}));

// ─── Test Data ───────────────────────────────────────────────────────────────

const defaultTemplate: LandingPageTemplateConfig = {
    id: 1,
    name: 'Default GFZ Data Services',
    slug: 'default_gfz',
    is_default: true,
    logo_path: null,
    logo_filename: null,
    logo_url: null,
    right_column_order: ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'location'],
    left_column_order: ['files', 'contact', 'model_description', 'related_work'],
    created_by: null,
    creator: null,
    landing_pages_count: 5,
    created_at: '2025-01-01T00:00:00Z',
    updated_at: '2025-01-01T00:00:00Z',
};

const customTemplate: LandingPageTemplateConfig = {
    id: 2,
    name: 'Geophysics Template',
    slug: 'geophysics-template-abc123',
    is_default: false,
    logo_path: 'landing-page-logos/geophysics/logo.png',
    logo_filename: 'logo.png',
    logo_url: 'http://localhost/storage/landing-page-logos/geophysics/logo.png',
    right_column_order: ['location', 'descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
    left_column_order: ['contact', 'files', 'model_description', 'related_work'],
    created_by: 1,
    creator: { id: 1, name: 'Admin User' },
    landing_pages_count: 2,
    created_at: '2025-03-15T10:00:00Z',
    updated_at: '2025-03-15T10:00:00Z',
};

const customTemplateNoLogo: LandingPageTemplateConfig = {
    id: 3,
    name: 'Minimal Template',
    slug: 'minimal-template-xyz789',
    is_default: false,
    logo_path: null,
    logo_filename: null,
    logo_url: null,
    right_column_order: ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'location'],
    left_column_order: ['files', 'contact', 'model_description', 'related_work'],
    created_by: 1,
    creator: { id: 1, name: 'Admin User' },
    landing_pages_count: 0,
    created_at: '2025-04-01T00:00:00Z',
    updated_at: '2025-04-01T00:00:00Z',
};

let mockTemplates: LandingPageTemplateConfig[] = [];

import LandingPageTemplatesPage from '@/pages/landing-page-templates';

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('LandingPageTemplatesPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockTemplates = [defaultTemplate, customTemplate, customTemplateNoLogo];
    });

    // ─── Rendering ───────────────────────────────────────────────────────

    describe('Rendering', () => {
        it('renders page title and description', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText('Landing Page Templates')).toBeInTheDocument();
            expect(screen.getByText(/Manage custom templates/i)).toBeInTheDocument();
        });

        it('renders New Template button', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByRole('button', { name: /New Template/i })).toBeInTheDocument();
        });

        it('renders all template cards', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText('Default GFZ Data Services')).toBeInTheDocument();
            expect(screen.getByText('Geophysics Template')).toBeInTheDocument();
            expect(screen.getByText('Minimal Template')).toBeInTheDocument();
        });

        it('shows Default badge on default template', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText('Default')).toBeInTheDocument();
        });

        it('shows usage count badges', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText('5 pages')).toBeInTheDocument();
            expect(screen.getByText('2 pages')).toBeInTheDocument();
        });

        it('shows "Built-in default template" for default template', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText('Built-in default template')).toBeInTheDocument();
        });

        it('shows creator name for custom templates', () => {
            render(<LandingPageTemplatesPage />);
            const creatorTexts = screen.getAllByText(/Created by Admin User/);
            expect(creatorTexts.length).toBe(2);
        });

        it('shows logo preview for templates with logo', () => {
            render(<LandingPageTemplatesPage />);
            const logoImg = screen.getByAltText('Geophysics Template logo');
            expect(logoImg).toBeInTheDocument();
            expect(logoImg).toHaveAttribute('src', customTemplate.logo_url);
        });

        it('shows section order lists', () => {
            render(<LandingPageTemplatesPage />);
            // Check section labels are rendered
            expect(screen.getAllByText('Abstract & Descriptions').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Creators / Authors').length).toBeGreaterThanOrEqual(1);
        });

        it('shows Edit, Upload Logo, and Delete buttons for custom templates', () => {
            render(<LandingPageTemplatesPage />);
            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            const deleteButtons = screen.getAllByRole('button', { name: /Delete/i });
            expect(editButtons.length).toBe(2); // 2 custom templates
            expect(deleteButtons.length).toBe(2);
        });

        it('does not show action buttons for default template', () => {
            mockTemplates = [defaultTemplate];
            render(<LandingPageTemplatesPage />);
            expect(screen.queryByRole('button', { name: /Edit/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Delete/i })).not.toBeInTheDocument();
        });

        it('shows empty state when no templates exist', () => {
            mockTemplates = [];
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText(/No templates found/i)).toBeInTheDocument();
        });

        it('shows Upload Logo button for template without logo', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByRole('button', { name: /Upload Logo/i })).toBeInTheDocument();
        });

        it('shows Replace Logo button for template with logo', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByRole('button', { name: /Replace Logo/i })).toBeInTheDocument();
        });

        it('shows Remove logo button inside logo preview', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getByRole('button', { name: /Remove logo/i })).toBeInTheDocument();
        });
    });

    // ─── Clone Dialog ────────────────────────────────────────────────────

    describe('Clone Dialog', () => {
        it('opens clone dialog when New Template is clicked', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));

            expect(screen.getByText('Clone Default Template')).toBeInTheDocument();
            expect(screen.getByLabelText('Template Name')).toBeInTheDocument();
        });

        it('clones template successfully', async () => {
            mockedAxiosPost.mockResolvedValue({ data: { message: 'Created', template: {} } });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));
            await user.type(screen.getByLabelText('Template Name'), 'My New Template');
            await user.click(screen.getByRole('button', { name: /Clone Template/i }));

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith('/landing-pages', { name: 'My New Template' });
            });
        });

        it('disables Clone button when name is empty', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));

            const cloneButton = screen.getByRole('button', { name: /Clone Template/i });
            expect(cloneButton).toBeDisabled();
        });

        it('shows validation error on duplicate name', async () => {
            const { toast } = await import('sonner');
            mockedAxiosPost.mockRejectedValue({
                isAxiosError: true,
                response: { data: { errors: { name: ['The name has already been taken.'] } } },
            });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));
            await user.type(screen.getByLabelText('Template Name'), 'Duplicate Name');
            await user.click(screen.getByRole('button', { name: /Clone Template/i }));

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('The name has already been taken.');
            });
        });

        it('shows generic error on clone failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosPost.mockRejectedValue(new Error('Network error'));
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));
            await user.type(screen.getByLabelText('Template Name'), 'Test');
            await user.click(screen.getByRole('button', { name: /Clone Template/i }));

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Failed to clone template');
            });
        });

        it('clones via Enter key', async () => {
            mockedAxiosPost.mockResolvedValue({ data: { message: 'Created', template: {} } });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));
            const nameInput = screen.getByLabelText('Template Name');
            await user.type(nameInput, 'Enter Template');
            await user.keyboard('{Enter}');

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith('/landing-pages', { name: 'Enter Template' });
            });
        });

        it('closes clone dialog via Cancel', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /New Template/i }));
            expect(screen.getByText('Clone Default Template')).toBeInTheDocument();

            await user.click(screen.getByRole('button', { name: /Cancel/i }));

            await waitFor(() => {
                expect(screen.queryByText('Clone Default Template')).not.toBeInTheDocument();
            });
        });
    });

    // ─── Edit Dialog ─────────────────────────────────────────────────────

    describe('Edit Dialog', () => {
        it('opens edit dialog with template data', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);

            expect(screen.getByText('Edit Template')).toBeInTheDocument();
            const nameInput = screen.getByLabelText('Template Name') as HTMLInputElement;
            expect(nameInput.value).toBe('Geophysics Template');
        });

        it('saves template changes', async () => {
            mockedAxiosPut.mockResolvedValue({ data: { message: 'Updated', template: {} } });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);

            const nameInput = screen.getByLabelText('Template Name');
            await user.clear(nameInput);
            await user.type(nameInput, 'Updated Template');

            await user.click(screen.getByRole('button', { name: /Save Changes/i }));

            await waitFor(() => {
                expect(mockedAxiosPut).toHaveBeenCalledWith(
                    `/landing-pages/${customTemplate.id}`,
                    expect.objectContaining({ name: 'Updated Template' }),
                );
            });
        });

        it('shows validation errors on save failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosPut.mockRejectedValue({
                isAxiosError: true,
                response: { data: { errors: { name: ['Name taken'], right_column_order: ['Invalid'] } } },
            });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);
            await user.click(screen.getByRole('button', { name: /Save Changes/i }));

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Name taken, Invalid');
            });
        });

        it('shows generic error on save failure without validation errors', async () => {
            const { toast } = await import('sonner');
            mockedAxiosPut.mockRejectedValue(new Error('Network error'));
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);
            await user.click(screen.getByRole('button', { name: /Save Changes/i }));

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Failed to update template');
            });
        });

        it('renders section order editors in edit dialog', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);

            expect(screen.getByText('Right Column (main content)')).toBeInTheDocument();
            expect(screen.getByText('Left Column (sidebar)')).toBeInTheDocument();
        });

        it('closes edit dialog via Cancel', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);
            expect(screen.getByText('Edit Template')).toBeInTheDocument();

            const cancelButtons = screen.getAllByRole('button', { name: /Cancel/i });
            await user.click(cancelButtons[cancelButtons.length - 1]);

            await waitFor(() => {
                expect(screen.queryByText('Edit Template')).not.toBeInTheDocument();
            });
        });

        it('disables Save when name is empty', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const editButtons = screen.getAllByRole('button', { name: /Edit/i });
            await user.click(editButtons[0]);

            const nameInput = screen.getByLabelText('Template Name');
            await user.clear(nameInput);

            const saveButton = screen.getByRole('button', { name: /Save Changes/i });
            expect(saveButton).toBeDisabled();
        });
    });

    // ─── Delete Dialog ───────────────────────────────────────────────────

    describe('Delete Dialog', () => {
        it('opens delete confirmation for custom template', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const deleteButtons = screen.getAllByRole('button', { name: /Delete/i });
            await user.click(deleteButtons[0]);

            expect(screen.getByText('Delete Template')).toBeInTheDocument();
            const matches = screen.getAllByText(/Geophysics Template/);
            expect(matches.length).toBeGreaterThanOrEqual(2); // card + dialog
        });

        it('shows in-use warning in delete dialog', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const deleteButtons = screen.getAllByRole('button', { name: /Delete/i });
            await user.click(deleteButtons[0]); // Geophysics Template has 2 pages

            expect(screen.getByText(/currently used by 2 landing page/i)).toBeInTheDocument();
        });

        it('deletes template successfully', async () => {
            mockedAxiosDelete.mockResolvedValue({ data: { message: 'Deleted' } });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const deleteButtons = screen.getAllByRole('button', { name: /Delete/i });
            await user.click(deleteButtons[1]); // Minimal Template (no pages)

            // Click the Delete button in the AlertDialog
            const confirmDelete = screen.getAllByRole('button', { name: /Delete/i });
            const alertDialogDelete = confirmDelete.find((btn) =>
                btn.className.includes('destructive') || btn.closest('[role="alertdialog"]'),
            );
            if (alertDialogDelete) await user.click(alertDialogDelete);

            await waitFor(() => {
                expect(mockedAxiosDelete).toHaveBeenCalledWith(`/landing-pages/${customTemplateNoLogo.id}`);
            });
        });

        it('shows error on delete failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosDelete.mockRejectedValue({
                isAxiosError: true,
                response: { data: { message: 'Template is in use by 2 landing page(s)' } },
            });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const deleteButtons = screen.getAllByRole('button', { name: /Delete/i });
            await user.click(deleteButtons[0]);

            const confirmButtons = screen.getAllByRole('button', { name: /Delete/i });
            const alertDialogDelete = confirmButtons.find((btn) => btn.closest('[role="alertdialog"]'));
            if (alertDialogDelete) await user.click(alertDialogDelete);

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Template is in use by 2 landing page(s)');
            });
        });

        it('shows generic error on delete failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosDelete.mockRejectedValue(new Error('Network error'));
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const deleteButtons = screen.getAllByRole('button', { name: /Delete/i });
            await user.click(deleteButtons[1]);

            const confirmButtons = screen.getAllByRole('button', { name: /Delete/i });
            const alertDialogDelete = confirmButtons.find((btn) => btn.closest('[role="alertdialog"]'));
            if (alertDialogDelete) await user.click(alertDialogDelete);

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Failed to delete template');
            });
        });
    });

    // ─── Logo Management ─────────────────────────────────────────────────

    describe('Logo Management', () => {
        it('triggers file input when Upload Logo is clicked', async () => {
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            const uploadButton = screen.getByRole('button', { name: /Upload Logo/i });
            await user.click(uploadButton);

            // The hidden file input should exist
            const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
            expect(fileInput).toBeInTheDocument();
            expect(fileInput).toHaveAttribute('accept', 'image/png,image/jpeg,image/svg+xml,image/webp');
        });

        it('uploads logo file on selection', async () => {
            mockedAxiosPost.mockResolvedValue({ data: { message: 'Logo uploaded', template: {} } });
            render(<LandingPageTemplatesPage />);

            // Click Upload Logo to set logoTargetId
            const uploadButton = screen.getByRole('button', { name: /Upload Logo/i });
            fireEvent.click(uploadButton);

            // Simulate file selection on the hidden input
            const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
            const file = new File(['logo-data'], 'test-logo.png', { type: 'image/png' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith(
                    `/landing-pages/${customTemplateNoLogo.id}/logo`,
                    expect.any(FormData),
                    expect.objectContaining({ headers: { 'Content-Type': 'multipart/form-data' } }),
                );
            });
        });

        it('shows error on logo upload failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosPost.mockRejectedValue({
                isAxiosError: true,
                response: { data: { errors: { logo: ['File too large'] } } },
            });
            render(<LandingPageTemplatesPage />);

            const uploadButton = screen.getByRole('button', { name: /Upload Logo/i });
            fireEvent.click(uploadButton);

            const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
            const file = new File(['huge-logo-data'], 'huge-logo.png', { type: 'image/png' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('File too large');
            });
        });

        it('shows generic error on logo upload failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosPost.mockRejectedValue(new Error('Network'));
            render(<LandingPageTemplatesPage />);

            const uploadButton = screen.getByRole('button', { name: /Upload Logo/i });
            fireEvent.click(uploadButton);

            const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
            const file = new File(['data'], 'logo.png', { type: 'image/png' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Failed to upload logo');
            });
        });

        it('deletes logo when remove button is clicked', async () => {
            mockedAxiosDelete.mockResolvedValue({ data: { message: 'Removed' } });
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /Remove logo/i }));

            await waitFor(() => {
                expect(mockedAxiosDelete).toHaveBeenCalledWith(`/landing-pages/${customTemplate.id}/logo`);
            });
        });

        it('shows error on logo delete failure', async () => {
            const { toast } = await import('sonner');
            mockedAxiosDelete.mockRejectedValue(new Error('Failed'));
            const user = userEvent.setup();
            render(<LandingPageTemplatesPage />);

            await user.click(screen.getByRole('button', { name: /Remove logo/i }));

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Failed to remove logo');
            });
        });
    });

    // ─── Section Labels ──────────────────────────────────────────────────

    describe('Section Labels', () => {
        it('renders right column section labels', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getAllByText('Abstract & Descriptions').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Creators / Authors').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Contributors').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Funding References').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Keywords / Subjects').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Metadata Download').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Location / Map').length).toBeGreaterThanOrEqual(1);
        });

        it('renders left column section labels', () => {
            render(<LandingPageTemplatesPage />);
            expect(screen.getAllByText('Files & Downloads').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Contact Person').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Model / Method Description').length).toBeGreaterThanOrEqual(1);
            expect(screen.getAllByText('Related Work').length).toBeGreaterThanOrEqual(1);
        });
    });

    // ─── Creator Display ─────────────────────────────────────────────────

    describe('Creator Display', () => {
        it('shows Unknown when creator is null', () => {
            mockTemplates = [{
                ...customTemplateNoLogo,
                creator: null,
            }];
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText(/Created by Unknown/i)).toBeInTheDocument();
        });

        it('shows singular page count', () => {
            mockTemplates = [{
                ...customTemplate,
                landing_pages_count: 1,
            }];
            render(<LandingPageTemplatesPage />);
            expect(screen.getByText('1 page')).toBeInTheDocument();
        });
    });
});
