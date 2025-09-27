/**
 * URL transformation for production deployments with path prefix
 */

export function setupUrlTransformation() {
    if (!import.meta.env.PROD) {
        return;
    }

    // Get the base path from meta tag
    const metaTag = document.querySelector('meta[name="app-base-path"]') as HTMLMetaElement;
    const basePath = metaTag?.content || '';
    
    if (!basePath) {
        console.log('No base path found, skipping URL transformation');
        return;
    }

    console.log('Setting up URL transformation with base path:', basePath);
    console.log('Current origin:', window.location.origin);

    // Override global fetch to fix URLs
    const originalFetch = window.fetch;
    window.fetch = function(input: RequestInfo | URL, init?: RequestInit) {
        let url = input;
        
        if (typeof input === 'string') {
            console.log('Fetch - Original URL:', input);
            
            // Fix various protocol issues
            let fixedUrl = input;
            
            // Fix double protocol: https://https:// -> https://
            if (fixedUrl.includes('https://https://') || fixedUrl.includes('http://http://')) {
                fixedUrl = fixedUrl.replace(/https?:\/\/https?:\/\//, 'https://');
                console.log('Fetch - Fixed double full protocol:', input, '->', fixedUrl);
            }
            
            // Fix malformed protocol: https//domain -> https://domain
            if (fixedUrl.match(/^https?\/\/[^/]/)) {
                fixedUrl = fixedUrl.replace(/^(https?)\/\//, '$1://');
                console.log('Fetch - Fixed malformed protocol:', input, '->', fixedUrl);
            }
            
            // If we fixed the URL, use that
            if (fixedUrl !== input) {
                url = fixedUrl;
                return originalFetch.call(this, url, init);
            }
            
            // If it's a relative URL starting with /, prepend base path
            if (input.startsWith('/') && !input.startsWith(basePath)) {
                url = basePath + input;
                console.log('Fetch - Transformed relative URL:', input, '->', url);
            }
            // If it's already an absolute URL, check if we need to fix the path
            else if (input.includes(window.location.origin) && !input.includes(basePath)) {
                const path = input.replace(window.location.origin, '');
                if (path.startsWith('/') && !path.startsWith(basePath)) {
                    url = window.location.origin + basePath + path;
                    console.log('Fetch - Transformed absolute URL:', input, '->', url);
                }
            }
        }
        
        return originalFetch.call(this, url, init);
    };

    // Override XMLHttpRequest for legacy AJAX calls
    const originalOpen = XMLHttpRequest.prototype.open;
    // @ts-expect-error - Complex overload handling
    XMLHttpRequest.prototype.open = function(method: string, url: string | URL, ...rest: unknown[]) {
        let transformedUrl = url;
        
        if (typeof url === 'string') {
            console.log('XHR - Original URL:', url, 'Method:', method);
            
            // Fix various protocol issues
            let fixedUrl = url;
            
            // Fix double protocol: https://https:// -> https://
            if (fixedUrl.includes('https://https://') || fixedUrl.includes('http://http://')) {
                fixedUrl = fixedUrl.replace(/https?:\/\/https?:\/\//, 'https://');
                console.log('XHR - Fixed double full protocol:', url, '->', fixedUrl);
            }
            
            // Fix malformed protocol: https//domain -> https://domain
            if (fixedUrl.match(/^https?\/\/[^/]/)) {
                fixedUrl = fixedUrl.replace(/^(https?)\/\//, '$1://');
                console.log('XHR - Fixed malformed protocol:', url, '->', fixedUrl);
            }
            
            // If we fixed the URL, use that
            if (fixedUrl !== url) {
                transformedUrl = fixedUrl;
            }
            // For relative URLs, prepend base path
            else if (url.startsWith('/') && !url.startsWith(basePath)) {
                transformedUrl = basePath + url;
                console.log('XHR - Transformed relative URL:', url, '->', transformedUrl);
            }
            // For absolute URLs that don't have the base path
            else if (url.includes(window.location.origin) && !url.includes(basePath)) {
                const path = url.replace(window.location.origin, '');
                if (path.startsWith('/') && !path.startsWith(basePath)) {
                    transformedUrl = window.location.origin + basePath + path;
                    console.log('XHR - Transformed absolute URL:', url, '->', transformedUrl);
                }
            }
            else {
                console.log('XHR - No transformation needed:', url);
            }
        }
        
        // @ts-expect-error - Complex overload handling
        return originalOpen.call(this, method, transformedUrl, ...rest);
    };
}