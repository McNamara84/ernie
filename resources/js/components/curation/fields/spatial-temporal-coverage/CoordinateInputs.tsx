import { Label } from '@/components/ui/label';

import InputField from '../input-field';

interface CoordinateInputsProps {
    latMin: string;
    lonMin: string;
    latMax: string;
    lonMax: string;
    onChange: (field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax', value: string) => void;
    showLabels?: boolean;
}

/**
 * Validates coordinate value and formats it to max 6 decimal places
 */
const formatCoordinate = (value: string): string => {
    if (!value) return '';

    // Remove any non-numeric characters except minus and dot
    let cleaned = value.replace(/[^\d.-]/g, '');

    // Ensure only one minus at the start
    if (cleaned.indexOf('-') > 0) {
        cleaned = cleaned.replace(/-/g, '');
    }
    if (cleaned.split('-').length > 2) {
        cleaned = cleaned.substring(0, cleaned.lastIndexOf('-'));
    }

    // Ensure only one decimal point
    const parts = cleaned.split('.');
    if (parts.length > 2) {
        cleaned = parts[0] + '.' + parts.slice(1).join('');
    }

    // Limit to 6 decimal places
    if (parts.length === 2 && parts[1].length > 6) {
        cleaned = parts[0] + '.' + parts[1].substring(0, 6);
    }

    return cleaned;
};

/**
 * Validates latitude range (-90 to +90)
 */
const isValidLatitude = (value: string): boolean => {
    if (!value) return true; // Empty is valid (will be caught by required validation)
    const num = parseFloat(value);
    return !isNaN(num) && num >= -90 && num <= 90;
};

/**
 * Validates longitude range (-180 to +180)
 */
const isValidLongitude = (value: string): boolean => {
    if (!value) return true; // Empty is valid (will be caught by required validation)
    const num = parseFloat(value);
    return !isNaN(num) && num >= -180 && num <= 180;
};

export default function CoordinateInputs({ latMin, lonMin, latMax, lonMax, onChange, showLabels = true }: CoordinateInputsProps) {
    const handleChange = (field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax', value: string) => {
        const formatted = formatCoordinate(value);
        onChange(field, formatted);
    };

    return (
        <div className="space-y-4">
            {showLabels && <Label className="text-sm font-medium">Coordinates</Label>}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {/* Min Coordinates (Required) */}
                <div className="space-y-3">
                    <Label className="text-xs font-semibold text-muted-foreground uppercase">Min (Required)</Label>
                    <div className="space-y-2">
                        <InputField
                            id="lat-min"
                            label="Latitude"
                            type="text"
                            value={latMin}
                            onChange={(e) => handleChange('latMin', e.target.value)}
                            placeholder="e.g., 48.137154"
                            required
                            className={latMin && !isValidLatitude(latMin) ? 'border-destructive' : ''}
                        />
                        {latMin && !isValidLatitude(latMin) && <p className="text-xs text-destructive">Latitude must be between -90 and +90</p>}

                        <InputField
                            id="lon-min"
                            label="Longitude"
                            type="text"
                            value={lonMin}
                            onChange={(e) => handleChange('lonMin', e.target.value)}
                            placeholder="e.g., 11.576124"
                            required
                            className={lonMin && !isValidLongitude(lonMin) ? 'border-destructive' : ''}
                        />
                        {lonMin && !isValidLongitude(lonMin) && <p className="text-xs text-destructive">Longitude must be between -180 and +180</p>}
                    </div>
                </div>

                {/* Max Coordinates (Optional) */}
                <div className="space-y-3">
                    <Label className="text-xs font-semibold text-muted-foreground uppercase">Max (Optional)</Label>
                    <div className="space-y-2">
                        <InputField
                            id="lat-max"
                            label="Latitude"
                            type="text"
                            value={latMax}
                            onChange={(e) => handleChange('latMax', e.target.value)}
                            placeholder="e.g., 48.150000"
                            className={latMax && !isValidLatitude(latMax) ? 'border-destructive' : ''}
                        />
                        {latMax && !isValidLatitude(latMax) && <p className="text-xs text-destructive">Latitude must be between -90 and +90</p>}

                        <InputField
                            id="lon-max"
                            label="Longitude"
                            type="text"
                            value={lonMax}
                            onChange={(e) => handleChange('lonMax', e.target.value)}
                            placeholder="e.g., 11.600000"
                            className={lonMax && !isValidLongitude(lonMax) ? 'border-destructive' : ''}
                        />
                        {lonMax && !isValidLongitude(lonMax) && <p className="text-xs text-destructive">Longitude must be between -180 and +180</p>}
                    </div>
                </div>
            </div>

            {/* Validation hint for min < max */}
            {latMin && latMax && isValidLatitude(latMin) && isValidLatitude(latMax) && parseFloat(latMin) >= parseFloat(latMax) && (
                <p className="text-xs text-destructive">Latitude Min must be less than Latitude Max</p>
            )}
            {lonMin && lonMax && isValidLongitude(lonMin) && isValidLongitude(lonMax) && parseFloat(lonMin) >= parseFloat(lonMax) && (
                <p className="text-xs text-destructive">Longitude Min must be less than Longitude Max</p>
            )}
        </div>
    );
}
