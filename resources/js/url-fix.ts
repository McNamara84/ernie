/**
 * URL transformation for production deployments with path prefix
 */
import { getBasePath, withBasePath } from './lib/base-path';

export function setupUrlTransformation() {
    if (!import.meta.env.PROD) {
        console.log('Development mode detected, skipping URL transformation');
        return;
    }

    const basePath = getBasePath();

    if (!basePath) {
        console.log('No base path found, skipping URL transformation');
        return;
    }

    console.log('Setting up URL transformation with base path:', basePath);
    console.log('Current origin:', window.location.origin);

    // Helper function to fix malformed URLs before transformation
    const fixProtocolMalformation = (url: string): string => {
        console.log('Fixing protocol malformation for:', url);

        // Fix all possible protocol malformations with a more aggressive approach
        let fixed = url;

        // Pattern 1: https://https:// or http://http:// -> https://
        if (fixed.includes('https://https://') || fixed.includes('http://http://')) {
            fixed = fixed.replace(/https?:\/\/https?:\/\//, 'https://');
            console.log('Fixed double protocol with ://', url, '->', fixed);
            return fixed;
        }

        // Pattern 2: https://https// or http://http// -> https://
        if (fixed.includes('https://https//') || fixed.includes('http://http//')) {
            fixed = fixed.replace(/https?:\/\/https?\/{2}/, 'https://');
            console.log('Fixed double protocol with //', url, '->', fixed);
            return fixed;
        }

        // Pattern 3: https://http// or http://https// -> https://
        if (fixed.includes('https://http//') || fixed.includes('http://https//')) {
            fixed = fixed.replace(/https?:\/\/https?\/{2}/, 'https://');
            console.log('Fixed mixed protocol with //', url, '->', fixed);
            return fixed;
        }

        // Pattern 4: Any remaining malformed protocol at start
        if (fixed.match(/^https?\/{2,}[^/]/)) {
            fixed = fixed.replace(/^(https?)\/{2,}/, '$1://');
            console.log('Fixed malformed start protocol:', url, '->', fixed);
            return fixed;
        }

        // Pattern 5: Multiple slashes after protocol
        if (fixed.match(/^https?:\/{3,}/)) {
            fixed = fixed.replace(/^(https?:)\/{3,}/, '$1//');
            console.log('Fixed multiple slashes after protocol:', url, '->', fixed);
            return fixed;
        }

        console.log('No protocol malformation detected for:', url);
        return fixed;
    };

    // Helper function to transform URLs using base-path library
    const transformUrl = (url: string): string => {
        console.log('=== URL TRANSFORM START ===');
        console.log('Input URL:', url);

        // First fix any protocol malformation
        const protocolFixed = fixProtocolMalformation(url);

        // If protocol was fixed and it's now a valid absolute URL, return it
        if (protocolFixed !== url) {
            console.log('Protocol was fixed, returning:', protocolFixed);
            console.log('=== URL TRANSFORM END ===');
            return protocolFixed;
        }

        console.log('No protocol fixes needed, proceeding with path transformation');

        // If it's a relative URL, use withBasePath
        if (url.startsWith('/')) {
            const withBase = withBasePath(url);
            console.log('Applied withBasePath:', url, '->', withBase);
            console.log('=== URL TRANSFORM END ===');
            return withBase;
        }

        // If it's an absolute URL with our origin but missing base path
        if (url.includes(window.location.origin)) {
            console.log('Absolute URL with our origin detected');
            const path = url.replace(window.location.origin, '');
            console.log('Extracted path:', path);

            if (path.startsWith('/') && !path.startsWith(basePath)) {
                const finalUrl = window.location.origin + withBasePath(path);
                console.log('Applied basePath to absolute URL:', url, '->', finalUrl);

                // Double-check for protocol malformation after transformation
                const doubleChecked = fixProtocolMalformation(finalUrl);
                if (doubleChecked !== finalUrl) {
                    console.log('CRITICAL: Found malformation after basePath transform!', finalUrl, '->', doubleChecked);
                    console.log('=== URL TRANSFORM END ===');
                    return doubleChecked;
                }

                console.log('=== URL TRANSFORM END ===');
                return finalUrl;
            }
        }

        // No transformation needed
        console.log('No transformation needed for:', url);
        console.log('=== URL TRANSFORM END ===');
        return url;
    };

    // Override global fetch for all modern HTTP requests
    const originalFetch = window.fetch;
    window.fetch = function (input: RequestInfo | URL, init?: RequestInit) {
        let url = input;

        if (typeof input === 'string') {
            console.log('=== FETCH OVERRIDE ===');
            console.log('Original input:', input);

            const transformed = transformUrl(input);
            if (transformed !== input) {
                console.log('Fetch transformed:', input, '->', transformed);
                console.log('Method:', init?.method || 'GET');
                url = transformed;
            } else {
                console.log('Fetch no transformation needed for:', input);
            }

            console.log('Final URL for fetch:', url);
            console.log('======================');
        } else if (input instanceof URL) {
            const urlStr = input.toString();
            console.log('=== FETCH URL OVERRIDE ===');
            console.log('Original URL object:', urlStr);

            const transformed = transformUrl(urlStr);
            if (transformed !== urlStr) {
                console.log('Fetch URL transformed:', urlStr, '->', transformed);
                url = new URL(transformed);
            }
            console.log('==========================');
        }

        return originalFetch.call(this, url, init);
    };

    // Override XMLHttpRequest for legacy AJAX calls (including Inertia.js)
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method: string, url: string | URL, async?: boolean, user?: string | null, password?: string | null) {
        let transformedUrl = url;

        if (typeof url === 'string') {
            console.log('=== XHR OVERRIDE ===');
            console.log('Original XHR URL:', url, 'Method:', method);

            const transformed = transformUrl(url);
            if (transformed !== url) {
                console.log(`XHR ${method} transformed:`, url, '->', transformed);
                transformedUrl = transformed;
            } else {
                console.log(`XHR ${method} no transformation needed:`, url);
            }

            console.log('Final XHR URL:', transformedUrl);
            console.log('===================');
        } else if (url instanceof URL) {
            const urlStr = url.toString();
            console.log('=== XHR URL OVERRIDE ===');
            console.log('Original XHR URL object:', urlStr);

            const transformed = transformUrl(urlStr);
            if (transformed !== urlStr) {
                console.log(`XHR ${method} URL transformed:`, urlStr, '->', transformed);
                transformedUrl = new URL(transformed);
            }
            console.log('========================');
        }

        return originalOpen.call(this, method, transformedUrl, async ?? true, user ?? null, password ?? null);
    };

    // Override setAttribute to catch dynamic URL assignments
    const originalSetAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function (name: string, value: string) {
        if ((name === 'src' || name === 'href') && typeof value === 'string') {
            const transformed = transformUrl(value);
            if (transformed !== value) {
                console.log(`Element ${name} transformed:`, value, '->', transformed);
                return originalSetAttribute.call(this, name, transformed);
            }
        }

        return originalSetAttribute.call(this, name, value);
    };

    // Handle Image constructor for dynamic favicon loading
    const OriginalImage = window.Image;
    // @ts-expect-error - Overriding native Image constructor
    window.Image = function (this: HTMLImageElement, width?: number, height?: number) {
        const img = new OriginalImage(width, height);

        // Override src setter
        const srcDescriptor = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
        if (srcDescriptor && srcDescriptor.set) {
            const originalSetter = srcDescriptor.set;
            Object.defineProperty(img, 'src', {
                get: srcDescriptor.get,
                set: function (value: string) {
                    const transformed = transformUrl(value);
                    if (transformed !== value) {
                        console.log('Image src transformed:', value, '->', transformed);
                    }
                    return originalSetter.call(this, transformed);
                },
                configurable: true,
                enumerable: true,
            });
        }

        return img;
    };

    // Preserve static properties
    Object.setPrototypeOf(window.Image, OriginalImage);
    window.Image.prototype = OriginalImage.prototype;
}
