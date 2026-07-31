import type { Dispatch, SetStateAction } from 'react';

import type { LandingPageFile } from '@/types/landing-page';

import { ContentDescriptorFields } from './ContentDescriptorFields';

interface ImportedFileDescriptorFieldsProps {
    files: LandingPageFile[];
    setFiles: Dispatch<SetStateAction<LandingPageFile[]>>;
    formats: Array<{ id: number; value: string }>;
    sizes: Array<{ id: number; label: string; content_size: string }>;
}

export function ImportedFileDescriptorFields({ files, setFiles, formats, sizes }: ImportedFileDescriptorFieldsProps) {
    if (files.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium">Machine-readable file descriptors</p>
            {files.map((file) => (
                <div key={file.id} className="space-y-2 rounded-md border p-3">
                    <p className="truncate text-xs text-muted-foreground">{file.url}</p>
                    <ContentDescriptorFields
                        formatId={file.format_id ?? null}
                        sizeId={file.size_id ?? null}
                        formats={formats}
                        sizes={sizes}
                        onFormatChange={(formatId) =>
                            setFiles((current) => current.map((entry) => (entry.id === file.id ? { ...entry, format_id: formatId } : entry)))
                        }
                        onSizeChange={(sizeId) =>
                            setFiles((current) => current.map((entry) => (entry.id === file.id ? { ...entry, size_id: sizeId } : entry)))
                        }
                        testIdPrefix={`imported-file-${file.id}`}
                    />
                </div>
            ))}
        </div>
    );
}
