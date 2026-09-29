import { AlertCircle, CheckCircle2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import type { ImportedMetadata } from '@/lib/imported-metadata';

interface EditorMetadataUploadProps {
    onImported: (metadata: ImportedMetadata) => void;
    onImportingChange: (importing: boolean) => void;
}

type UploadStatus = { kind: 'error' | 'success'; message: string } | null;

export function EditorMetadataUpload({ onImported, onImportingChange }: EditorMetadataUploadProps) {
    const [open, setOpen] = useState(true);
    const [uploading, setUploading] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [status, setStatus] = useState<UploadStatus>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    async function importFile(file: File) {
        const name = file.name.toLowerCase();
        const route = name.endsWith('.xml')
            ? '/editor/upload-xml/preview'
            : name.endsWith('.json') || name.endsWith('.jsonld')
              ? '/editor/upload-json/preview'
              : null;
        if (!route) {
            setStatus({ kind: 'error', message: 'Select a DataCite XML, JSON, or JSON-LD file.' });
            return;
        }

        const csrfHeaders = buildCsrfHeaders();
        if (!csrfHeaders['X-CSRF-TOKEN'] && !csrfHeaders['X-XSRF-TOKEN']) {
            setStatus({ kind: 'error', message: 'Session token is missing. Reload the page and try again.' });
            return;
        }

        setUploading(true);
        onImportingChange(true);
        setStatus(null);
        try {
            const body = new FormData();
            body.append('file', file);
            const response = await fetch(route, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { ...csrfHeaders, Accept: 'application/json' },
            });
            const result = (await response.json().catch(() => null)) as { success?: boolean; metadata?: ImportedMetadata; message?: string } | null;
            if (response.status === 419) throw new Error('Your session has expired. Reload the page and try again.');
            if (!response.ok || !result?.success || !result.metadata)
                throw new Error(result?.message || 'The file could not be imported. Try again.');
            onImported(result.metadata);
            setStatus({ kind: 'success', message: `${file.name} was added. Existing entries were kept.` });
        } catch (error) {
            setStatus({ kind: 'error', message: error instanceof Error ? error.message : 'The file could not be imported.' });
        } finally {
            setUploading(false);
            onImportingChange(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    }

    return (
        <section className="w-full border-b" data-testid="editor-metadata-upload">
            <h3>
                <Button
                    type="button"
                    variant="ghost"
                    className="h-auto w-full justify-between px-0 py-4 text-left font-medium"
                    aria-expanded={open}
                    aria-controls="editor-upload-content"
                    onClick={() => setOpen((value) => !value)}
                >
                    <span>Upload DataCite metadata</span>
                    <span className="text-sm text-muted-foreground">{open ? 'Minimize' : 'Expand'}</span>
                </Button>
            </h3>
            {open && (
                <div id="editor-upload-content" className="space-y-3 pb-5">
                    <p className="text-sm text-muted-foreground">
                        Add XML, JSON, or JSON-LD metadata to this new resource. Entered values remain in place.
                    </p>
                    <div
                        className={`rounded-xl border-2 border-dashed p-6 text-center ${dragging ? 'border-primary bg-accent/60' : 'border-muted-foreground/25 bg-muted/40'}`}
                        onDragOver={(event) => {
                            event.preventDefault();
                            setDragging(true);
                        }}
                        onDragLeave={(event) => {
                            event.preventDefault();
                            setDragging(false);
                        }}
                        onDrop={(event) => {
                            event.preventDefault();
                            setDragging(false);
                            if (event.dataTransfer.files[0] && !uploading) void importFile(event.dataTransfer.files[0]);
                        }}
                    >
                        <Upload className="mx-auto mb-2 size-7 text-muted-foreground" aria-hidden="true" />
                        <p className="mb-3 text-sm">Drop a DataCite file here or choose one</p>
                        <input
                            ref={inputRef}
                            type="file"
                            accept=".xml,.json,.jsonld"
                            hidden
                            data-testid="editor-metadata-file-input"
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                if (file) void importFile(file);
                            }}
                        />
                        <Button type="button" variant="outline" disabled={uploading} aria-busy={uploading} onClick={() => inputRef.current?.click()}>
                            {uploading ? 'Importing…' : 'Choose file'}
                        </Button>
                    </div>
                    {status && (
                        <p
                            role={status.kind === 'error' ? 'alert' : 'status'}
                            className={`flex items-center gap-2 text-sm ${status.kind === 'error' ? 'text-destructive' : 'text-foreground'}`}
                        >
                            {status.kind === 'error' ? (
                                <AlertCircle className="size-4" aria-hidden="true" />
                            ) : (
                                <CheckCircle2 className="size-4" aria-hidden="true" />
                            )}
                            {status.message}
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}
