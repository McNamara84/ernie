import { describe, expect, it, vi } from 'vitest';

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
    },
}));

import { toast } from 'sonner';

import { feedback } from '@/lib/feedback';

describe('feedback', () => {
    it('shows success toast for saved', () => {
        feedback.saved('Resource');
        expect(toast.success).toHaveBeenCalledWith('Resource saved successfully');
    });

    it('shows success toast for deleted', () => {
        feedback.deleted('Resource');
        expect(toast.success).toHaveBeenCalledWith('Resource deleted');
    });

    it('shows success toast for created', () => {
        feedback.created('User');
        expect(toast.success).toHaveBeenCalledWith('User created');
    });

    it('shows success toast for updated', () => {
        feedback.updated('Profile');
        expect(toast.success).toHaveBeenCalledWith('Profile updated');
    });

    it('shows error toast', () => {
        feedback.error('Something went wrong');
        expect(toast.error).toHaveBeenCalledWith('Something went wrong');
    });

    it('shows network error toast', () => {
        feedback.networkError();
        expect(toast.error).toHaveBeenCalledWith('Network error. Please try again.');
    });

    it('shows session expired toast', () => {
        feedback.sessionExpired();
        expect(toast.error).toHaveBeenCalledWith('Session expired. Please log in again.');
    });

    it('shows import started toast', () => {
        feedback.importStarted('DataCite');
        expect(toast.info).toHaveBeenCalledWith('DataCite import started');
    });

    it('shows import completed with count', () => {
        feedback.importCompleted('DataCite', 5);
        expect(toast.success).toHaveBeenCalledWith('DataCite import completed: 5 items imported');
    });

    it('shows import completed without count', () => {
        feedback.importCompleted('DataCite');
        expect(toast.success).toHaveBeenCalledWith('DataCite import completed');
    });
});
