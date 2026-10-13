import type { DataCiteUploadResult } from '@/components/unified-dropzone';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { editor as editorRoute } from '@/routes';
import { uploadJson as uploadJsonRoute, uploadXml as uploadXmlRoute } from '@/routes/dashboard';
import type { UploadErrorResponse } from '@/types/upload';

type UploadSessionResponse = {
    resourceId?: number | string | null;
    sessionKey?: string | null;
};

async function uploadSessionFile(file: File, route: { url: () => string }, sessionQueryKey: string): Promise<DataCiteUploadResult> {
    const csrfHeaders = buildCsrfHeaders();

    if (!csrfHeaders['X-CSRF-TOKEN'] && !csrfHeaders['X-XSRF-TOKEN']) {
        throw new Error('CSRF token not found. Please reload the page (Ctrl+F5) and try again.');
    }

    const formData = new FormData();
    formData.append('file', file);
    const filename = file.name;

    try {
        const response = await fetch(route.url(), {
            method: 'POST',
            body: formData,
            headers: csrfHeaders,
            credentials: 'same-origin',
        });

        if (!response.ok) {
            if (response.status === 419) {
                window.location.reload();
                throw new Error('Session expired. Reloading page...');
            }

            let message = `Failed to upload ${filename}`;
            try {
                const errorData: UploadErrorResponse = await response.json();
                if (errorData.message) {
                    message = errorData.message;
                }
            } catch {
                // A non-JSON error response may come from a proxy or server failure.
            }
            throw new Error(message);
        }

        const data: UploadSessionResponse = await response.json();
        const resourceId = data.resourceId !== undefined && data.resourceId !== null ? String(data.resourceId).trim() : '';
        const sessionKey = data.sessionKey ? String(data.sessionKey).trim() : '';

        if (resourceId !== '') {
            return {
                success: true,
                uploadKind: 'datacite',
                filename,
                resourceId,
                sessionKey: sessionKey || null,
                editorUrl: editorRoute({ query: { resourceId } }).url,
            };
        }

        if (sessionKey !== '') {
            return {
                success: true,
                uploadKind: 'datacite',
                filename,
                resourceId: null,
                sessionKey,
                editorUrl: editorRoute({ query: { [sessionQueryKey]: sessionKey } }).url,
            };
        }

        throw new Error('Upload completed but no editor target was returned for ' + filename + '.');
    } catch (error) {
        console.error(`${sessionQueryKey} upload failed`, error);
        if (error instanceof Error) {
            throw error;
        }
        throw new Error(`Failed to upload ${filename}`, { cause: error });
    }
}

export const handleXmlFiles = async (files: File[]): Promise<DataCiteUploadResult | undefined> => {
    if (!files.length) return undefined;
    return uploadSessionFile(files[0], uploadXmlRoute, 'xmlSession');
};

export const handleJsonFiles = async (files: File[]): Promise<DataCiteUploadResult | undefined> => {
    if (!files.length) return undefined;
    return uploadSessionFile(files[0], uploadJsonRoute, 'jsonSession');
};
