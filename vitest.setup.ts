import '@testing-library/jest-dom/vitest';

class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
    value: ResizeObserver,
});

// Mock IntersectionObserver for infinite scrolling tests
class IntersectionObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

Object.defineProperty(globalThis, 'IntersectionObserver', {
    writable: true,
    configurable: true,
    value: IntersectionObserver,
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

// Mock hasPointerCapture for Radix UI Select compatibility
if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = function () {
        return false;
    };
}

if (!Element.prototype.setPointerCapture) {
    Element.prototype.setPointerCapture = function () {
        // No-op
    };
}

if (!Element.prototype.releasePointerCapture) {
    Element.prototype.releasePointerCapture = function () {
        // No-op
    };
}

// Mock scrollIntoView for Radix UI Select compatibility
if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = function () {
        // No-op
    };
}

// Mock window.scrollTo
if (typeof window.scrollTo !== 'function') {
    window.scrollTo = function () {
        // No-op
    };
}

// Set environment variables for consistent URL generation in tests
process.env.VITE_APP_URL = '';
process.env.APP_URL = '';

// Mock localStorage for tests
class LocalStorageMock {
    private store: Record<string, string> = {};

    clear() {
        this.store = {};
    }

    getItem(key: string) {
        return this.store[key] || null;
    }

    setItem(key: string, value: string) {
        this.store[key] = value.toString();
    }

    removeItem(key: string) {
        delete this.store[key];
    }

    get length() {
        return Object.keys(this.store).length;
    }

    key(index: number) {
        const keys = Object.keys(this.store);
        return keys[index] || null;
    }
}

global.localStorage = new LocalStorageMock() as Storage;

// Mock Clipboard API for tests
if (typeof navigator !== 'undefined') {
    Object.defineProperty(navigator, 'clipboard', {
        value: {
            writeText: () => Promise.resolve(),
            readText: () => Promise.resolve(''),
        },
        configurable: true,
        writable: true,
    });
}
