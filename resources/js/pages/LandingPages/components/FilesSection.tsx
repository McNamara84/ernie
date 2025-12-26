import { Download } from 'lucide-react';

interface License {
    id: number;
    name: string;
    spdx_id: string;
    reference: string;
}

interface FilesSectionProps {
    downloadUrl: string;
    licenses: License[];
}

export function FilesSection({ downloadUrl, licenses }: FilesSectionProps) {
    return (
        <div data-testid="files-section" className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="mb-4 text-lg font-semibold text-gray-900">Files</h3>

            <div className="space-y-3">
                {/* Download Link */}
                <a
                    href={downloadUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                    style={{ backgroundColor: '#0C2A63' }}
                >
                    <Download className="h-4 w-4" />
                    Download data and description
                </a>

                {/* License Badges */}
                {licenses.length > 0 && (
                    <div data-testid="license-section" className="flex flex-wrap gap-2">
                        {licenses.map((license) => (
                            <a
                                key={license.id}
                                href={license.reference}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800 transition-colors hover:bg-green-200"
                            >
                                {license.spdx_id || license.name}
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
