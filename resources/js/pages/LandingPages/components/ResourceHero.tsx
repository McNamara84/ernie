import { Copy, FlaskConical } from 'lucide-react';
import { useState } from 'react';

import { getResourceTypeIcon } from './ResourceTypeIcons';
import { getStatusConfig } from './StatusConfig';

interface ResourceHeroProps {
    resourceType: string;
    status: string;
    mainTitle: string;
    subtitle?: string;
    citation: string;
    /** Use FlaskConical icon for IGSN instead of resource type icon */
    useIgsnIcon?: boolean;
}

export function ResourceHero({ resourceType, status, mainTitle, subtitle, citation, useIgsnIcon = false }: ResourceHeroProps) {
    const [copied, setCopied] = useState(false);

    // Use FlaskConical for IGSN, otherwise use resource type icon
    const ResourceTypeIcon = useIgsnIcon ? FlaskConical : getResourceTypeIcon(resourceType);
    const statusConfig = getStatusConfig(status);
    const StatusIcon = statusConfig.icon;

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(citation);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard copy failed silently
        }
    };

    return (
        <div className="mx-8 my-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            {/* Top Row: Resource Type, Title, Status */}
            <div className="mb-6 flex items-start justify-between gap-4">
                {/* Left: Resource Type */}
                <div className="flex flex-col items-center gap-1.5">
                    <ResourceTypeIcon className="h-8 w-8 text-gray-700" strokeWidth={1.5} />
                    <span className="text-center text-xs text-gray-600">{resourceType}</span>
                </div>

                {/* Center: Title + Subtitle */}
                <div className="flex-1 space-y-1 text-center">
                    <h1 className="text-xl leading-tight font-bold text-gray-900">{mainTitle}</h1>
                    {subtitle && <h2 className="text-base font-normal text-gray-600 italic">{subtitle}</h2>}
                </div>

                {/* Right: Status */}
                <div className="flex flex-col items-center gap-1.5">
                    <StatusIcon className={`h-8 w-8 ${statusConfig.color}`} strokeWidth={1.5} />
                    <span className={`text-center text-xs font-medium ${statusConfig.textColor}`}>{statusConfig.label}</span>
                </div>
            </div>

            {/* Bottom: Citation */}
            <div className="border-t border-gray-200 pt-4">
                <div className="flex items-start gap-3">
                    <p className="flex-1 text-sm leading-relaxed text-gray-700">{citation}</p>
                    <button
                        onClick={handleCopy}
                        className="shrink-0 rounded p-2 transition-colors hover:bg-gray-100"
                        title={copied ? 'Copied!' : 'Copy citation'}
                        aria-label="Copy citation to clipboard"
                    >
                        <Copy className={`h-4 w-4 ${copied ? 'text-green-600' : 'text-gray-600'}`} />
                    </button>
                </div>
                {copied && <p className="mt-2 text-right text-xs text-green-600">Citation copied to clipboard!</p>}
            </div>
        </div>
    );
}
