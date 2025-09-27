import '@testing-library/jest-dom/vitest';

class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
    value: ResizeObserver,
});

// Set environment variables for consistent URL generation in tests
process.env.VITE_APP_URL = '';
process.env.APP_URL = '';
