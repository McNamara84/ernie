import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type FormatOption = { id: number; value: string };
type SizeOption = { id: number; label: string; content_size: string };

interface ContentDescriptorFieldsProps {
    formatId: number | null;
    sizeId: number | null;
    formats: FormatOption[];
    sizes: SizeOption[];
    onFormatChange: (id: number | null) => void;
    onSizeChange: (id: number | null) => void;
    testIdPrefix: string;
}

const NONE = '__none__';

export function ContentDescriptorFields({
    formatId,
    sizeId,
    formats,
    sizes,
    onFormatChange,
    onSizeChange,
    testIdPrefix,
}: ContentDescriptorFieldsProps) {
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <div className="space-y-1">
                <Label className="text-xs">MIME type</Label>
                <Select
                    value={formatId === null ? NONE : String(formatId)}
                    onValueChange={(value) => onFormatChange(value === NONE ? null : Number(value))}
                >
                    <SelectTrigger size="sm" data-testid={`${testIdPrefix}-format`}>
                        <SelectValue placeholder="Select format" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>No MIME type</SelectItem>
                        {formats.map((format) => (
                            <SelectItem key={format.id} value={String(format.id)}>
                                {format.value}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Digital size</Label>
                <Select
                    value={sizeId === null ? NONE : String(sizeId)}
                    onValueChange={(value) => onSizeChange(value === NONE ? null : Number(value))}
                >
                    <SelectTrigger size="sm" data-testid={`${testIdPrefix}-size`}>
                        <SelectValue placeholder="Select size" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>No digital size</SelectItem>
                        {sizes.map((size) => (
                            <SelectItem key={size.id} value={String(size.id)}>
                                {size.label} ({size.content_size} bytes)
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </div>
    );
}
