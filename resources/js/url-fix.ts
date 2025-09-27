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
        // Fix the worst case first: https://https// -> https://
        if (url.includes('https://https://') || url.includes('http://http://') || 
            url.includes('https://https//') || url.includes('http://http//')) {
            return url.replace(/https?:\/\/https?\/{1,2}/, 'https://');
        }
        
        // Fix simple malformed protocol: https//domain -> https://domain  
        if (url.match(/^https?\/\/[^/]/) && !url.includes('://')) {
            return url.replace(/^(https?)\/\//, '$1://');
        }
        
        return url;
    };

    // Helper function to transform URLs using base-path library
    const transformUrl = (url: string): string => {
        // First fix any protocol malformation
        const protocolFixed = fixProtocolMalformation(url);
        
        // If protocol was fixed and it's now a valid absolute URL, return it
        if (protocolFixed !== url) {
            console.log('Protocol fixed:', url, '->', protocolFixed);
            return protocolFixed;
        }
        
        // If it's a relative URL, use withBasePath
        if (url.startsWith('/')) {
            return withBasePath(url);
        }
        
        // If it's an absolute URL with our origin but missing base path
        if (url.includes(window.location.origin)) {
            const path = url.replace(window.location.origin, '');
            if (path.startsWith('/') && !path.startsWith(basePath)) {
                return window.location.origin + withBasePath(path);
            }
        }
        
        // No transformation needed
        return url;
    };

    // Override global fetch for all modern HTTP requests
    const originalFetch = window.fetch;
    window.fetch = function(input: RequestInfo | URL, init?: RequestInit) {
        let url = input;
        
        if (typeof input === 'string') {
            const transformed = transformUrl(input);
            if (transformed !== input) {
                console.log('Fetch transformed:', input, '->', transformed);
                url = transformed;
            }
        } else if (input instanceof URL) {
            const urlStr = input.toString();
            const transformed = transformUrl(urlStr);
            if (transformed !== urlStr) {
                console.log('Fetch URL transformed:', urlStr, '->', transformed);
                url = new URL(transformed);
            }
        }
        
        return originalFetch.call(this, url, init);
    };

    // Override XMLHttpRequest for legacy AJAX calls (including Inertia.js)
    const originalOpen = XMLHttpRequest.prototype.open;
    // @ts-expect-error - Complex overload handling
    XMLHttpRequest.prototype.open = function(method: string, url: string | URL, ...rest: unknown[]) {
        let transformedUrl = url;
        
        if (typeof url === 'string') {
            const transformed = transformUrl(url);
            if (transformed !== url) {
                console.log(`XHR ${method} transformed:`, url, '->', transformed);
                transformedUrl = transformed;
            }
        } else if (url instanceof URL) {
            const urlStr = url.toString();
            const transformed = transformUrl(urlStr);
            if (transformed !== urlStr) {
                console.log(`XHR ${method} URL transformed:`, urlStr, '->', transformed);
                transformedUrl = new URL(transformed);
            }
        }
        
        // @ts-expect-error - Complex overload handling
        return originalOpen.call(this, method, transformedUrl, ...rest);
    };

    // Override setAttribute to catch dynamic URL assignments
    const originalSetAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function(name: string, value: string) {
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
    window.Image = function(this: HTMLImageElement, width?: number, height?: number) {
        const img = new OriginalImage(width, height);
        
        // Override src setter
        const srcDescriptor = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
        if (srcDescriptor && srcDescriptor.set) {
            const originalSetter = srcDescriptor.set;
            Object.defineProperty(img, 'src', {
                get: srcDescriptor.get,
                set: function(value: string) {
                    const transformed = transformUrl(value);
                    if (transformed !== value) {
                        console.log('Image src transformed:', value, '->', transformed);
                    }
                    return originalSetter.call(this, transformed);
                },
                configurable: true,
                enumerable: true
            });
        }
        
        return img;
    };
    
    // Preserve static properties
    Object.setPrototypeOf(window.Image, OriginalImage);
    window.Image.prototype = OriginalImage.prototype;
}