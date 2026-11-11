import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ReleaseDataEmbargoButton } from '@/components/curation/release-data-embargo-button';

vi.mock('axios', () => ({ default: { post: vi.fn(), isAxiosError: vi.fn(() => true) } }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: { canReleaseData: true } }) }));

describe('explicit data embargo release', () => {
    beforeEach(() => vi.clearAllMocks());

    it('requires confirmation and reports a pending metadata sync separately', async () => {
        vi.mocked(axios.post).mockResolvedValue({ data: { synchronized: false, message: 'DataCite update pending' } });
        const onReleased = vi.fn();
        render(<ReleaseDataEmbargoButton resourceId={12} onReleased={onReleased} />);
        fireEvent.click(screen.getByRole('button', { name: 'Release data' }));
        expect(axios.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getAllByRole('button', { name: 'Release data' }).at(-1)!);
        await waitFor(() => expect(onReleased).toHaveBeenCalledWith(false));
        expect(axios.post).toHaveBeenCalledWith('/resources/12/release-data-embargo');
        expect(toast.warning).toHaveBeenCalledWith('DataCite update pending');
    });

    it('does not release when the confirmation is cancelled', () => {
        render(<ReleaseDataEmbargoButton resourceId={12} />);
        fireEvent.click(screen.getByRole('button', { name: 'Release data' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(axios.post).not.toHaveBeenCalled();
    });
});
