import '@testing-library/jest-dom/vitest';

class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
    value: ResizeObserver,
});

// Mock matchMedia for accessibility tests (prefers-reduced-motion)
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {}, // deprecated
        removeListener: () => {}, // deprecated
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => true,
    }),
});

// Set environment variables for consistent URL generation in tests
process.env.VITE_APP_URL = '';
process.env.APP_URL = '';
