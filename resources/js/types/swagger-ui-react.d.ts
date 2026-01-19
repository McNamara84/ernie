/**
 * Type declarations for swagger-ui-react
 *
 * This module doesn't have official @types/* package.
 * Minimal declarations for the features we use.
 */

declare module 'swagger-ui-react' {
    import type { ComponentType } from 'react';

    export interface SwaggerUIProps {
        /** The OpenAPI/Swagger spec object or URL */
        spec?: object | string;
        /** URL to fetch the spec from */
        url?: string;
        /** DOM element ID to render into */
        dom_id?: string;
        /** Layout name */
        layout?: string;
        /** Preset configurations */
        presets?: unknown[];
        /** Plugin functions */
        plugins?: unknown[];
        /** Deep linking */
        deepLinking?: boolean;
        /** Display operation ID */
        displayOperationId?: boolean;
        /** Default models expand depth */
        defaultModelsExpandDepth?: number;
        /** Default model expand depth */
        defaultModelExpandDepth?: number;
        /** Show extensions */
        showExtensions?: boolean;
        /** Show common extensions */
        showCommonExtensions?: boolean;
        /** Doc expansion */
        docExpansion?: 'list' | 'full' | 'none';
        /** Filter */
        filter?: boolean | string;
        /** Request interceptor */
        requestInterceptor?: (request: Request) => Request;
        /** Response interceptor */
        responseInterceptor?: (response: Response) => Response;
        /** Try it out enabled */
        tryItOutEnabled?: boolean;
        /** Display request duration */
        displayRequestDuration?: boolean;
        /** Persist authorization */
        persistAuthorization?: boolean;
        /** Supported submit methods */
        supportedSubmitMethods?: Array<'get' | 'put' | 'post' | 'delete' | 'options' | 'head' | 'patch' | 'trace'>;
        /** OAuth2 redirect URL */
        oauth2RedirectUrl?: string;
    }

    const SwaggerUI: ComponentType<SwaggerUIProps>;
    export default SwaggerUI;
}

declare module 'swagger-ui-react/swagger-ui.css' {
    const styles: string;
    export default styles;
}
