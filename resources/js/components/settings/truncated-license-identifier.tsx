import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

export const LICENSE_IDENTIFIER_VISIBLE_LENGTH = 50;

export function abbreviateLicenseIdentifier(identifier: string): string {
    if (identifier.length <= LICENSE_IDENTIFIER_VISIBLE_LENGTH) {
        return identifier;
    }

    return `${identifier.slice(0, LICENSE_IDENTIFIER_VISIBLE_LENGTH)}…`;
}

interface TruncatedLicenseIdentifierProps {
    identifier: string;
}

export function TruncatedLicenseIdentifier({ identifier }: TruncatedLicenseIdentifierProps) {
    const abbreviated = abbreviateLicenseIdentifier(identifier);

    if (abbreviated === identifier) {
        return <span className="font-mono text-sm">{identifier}</span>;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className="inline-block max-w-full cursor-help font-mono text-sm focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label={`Full license identifier: ${identifier}`}
                >
                    <span aria-hidden="true">{abbreviated}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-[min(40rem,calc(100vw-2rem))] text-left text-pretty break-all" sideOffset={6}>
                {identifier}
            </TooltipContent>
        </Tooltip>
    );
}
