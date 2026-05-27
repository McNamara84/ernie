import { ExternalLink } from 'lucide-react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { LandingPageDomain } from '@/types/landing-page';

interface ExternalLandingPageFieldsProps {
    availableDomains: LandingPageDomain[];
    externalDomainId: string;
    onExternalDomainIdChange: (value: string) => void;
    externalPath: string;
    onExternalPathChange: (value: string) => void;
    computedExternalUrl: string | null;
    pathExample: string;
}

export function ExternalLandingPageFields({
    availableDomains,
    externalDomainId,
    onExternalDomainIdChange,
    externalPath,
    onExternalPathChange,
    computedExternalUrl,
    pathExample,
}: ExternalLandingPageFieldsProps) {
    return (
        <div className="space-y-4 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-950/20">
            <div className="flex items-center gap-2 text-sm font-medium text-blue-900 dark:text-blue-100">
                <ExternalLink className="size-4" />
                External URL Configuration
            </div>

            <div className="space-y-2">
                <Label htmlFor="external-domain">Domain</Label>
                <Select value={externalDomainId} onValueChange={onExternalDomainIdChange}>
                    <SelectTrigger id="external-domain">
                        <SelectValue placeholder="Select a domain" />
                    </SelectTrigger>
                    <SelectContent>
                        {availableDomains.map((domain) => (
                            <SelectItem key={domain.id} value={String(domain.id)}>
                                {domain.domain}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {availableDomains.length === 0 && (
                    <p className="text-xs text-amber-600 dark:text-amber-400">
                        No domains configured. An administrator can add domains in Editor Settings.
                    </p>
                )}
            </div>

            <div className="space-y-2">
                <Label htmlFor="external-path">Path</Label>
                <Input
                    id="external-path"
                    type="text"
                    placeholder="/path/to/landing-page"
                    value={externalPath}
                    onChange={(event) => onExternalPathChange(event.target.value)}
                />
                <p className="text-sm text-muted-foreground">Path appended to the domain (e.g. {pathExample})</p>
            </div>

            {computedExternalUrl && (
                <div className="space-y-1">
                    <Label className="text-xs text-muted-foreground">Resulting URL</Label>
                    <p className="break-all rounded bg-white/80 px-2 py-1 font-mono text-xs text-blue-800 dark:bg-gray-900/50 dark:text-blue-200">
                        {computedExternalUrl}
                    </p>
                </div>
            )}
        </div>
    );
}