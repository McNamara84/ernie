import { Label } from '@/components/ui/label';

import InputField from '../input-field';

interface CoordinateInputsProps {
    latMin: string;
    lonMin: string;
    latMax: string;
    lonMax: string;
    onChange: (field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax', value: string) => void;
    showLabels?: boolean;
    coordinateOrder?: 'min-max' | 'lat-lon';
}

type CoordinateField = 'latMin' | 'lonMin' | 'latMax' | 'lonMax';

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

export default function CoordinateInputs({
    latMin,
    lonMin,
    latMax,
    lonMax,
    onChange,
    showLabels = true,
    coordinateOrder = 'min-max',
}: CoordinateInputsProps) {
    const handleChange = (field: CoordinateField, value: string) => {
        const formatted = formatCoordinate(value);
        onChange(field, formatted);
    };

    const renderCoordinateInput = ({
        field,
        id,
        label,
        value,
        placeholder,
        required = false,
        type,
    }: {
        field: CoordinateField;
        id: string;
        label: string;
        value: string;
        placeholder: string;
        required?: boolean;
        type: 'latitude' | 'longitude';
    }) => {
        const isInvalid = value !== '' && (type === 'latitude' ? !isValidLatitude(value) : !isValidLongitude(value));
        const errorMessage = type === 'latitude' ? 'Latitude must be between -90 and +90' : 'Longitude must be between -180 and +180';

        return (
            <div className="space-y-2">
                <InputField
                    id={id}
                    label={label}
                    type="text"
                    value={value}
                    onChange={(e) => handleChange(field, e.target.value)}
                    placeholder={placeholder}
                    required={required}
                    inputClassName={isInvalid ? 'border-destructive' : ''}
                />
                {isInvalid && <p className="text-xs text-destructive">{errorMessage}</p>}
            </div>
        );
    };

    return (
        <div className="space-y-4">
            {showLabels && <Label className="text-sm font-medium">Coordinates</Label>}

            {coordinateOrder === 'lat-lon' ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-3">
                        <Label className="text-xs font-semibold text-muted-foreground uppercase">Latitude</Label>
                        <div className="space-y-2">
                            {renderCoordinateInput({
                                field: 'latMin',
                                id: 'lat-min',
                                label: 'Min',
                                value: latMin,
                                placeholder: 'e.g., 48.137154',
                                required: true,
                                type: 'latitude',
                            })}
                            {renderCoordinateInput({
                                field: 'latMax',
                                id: 'lat-max',
                                label: 'Max',
                                value: latMax,
                                placeholder: 'e.g., 48.150000',
                                type: 'latitude',
                            })}
                        </div>
                    </div>

                    <div className="space-y-3">
                        <Label className="text-xs font-semibold text-muted-foreground uppercase">Longitude</Label>
                        <div className="space-y-2">
                            {renderCoordinateInput({
                                field: 'lonMin',
                                id: 'lon-min',
                                label: 'Min',
                                value: lonMin,
                                placeholder: 'e.g., 11.576124',
                                required: true,
                                type: 'longitude',
                            })}
                            {renderCoordinateInput({
                                field: 'lonMax',
                                id: 'lon-max',
                                label: 'Max',
                                value: lonMax,
                                placeholder: 'e.g., 11.600000',
                                type: 'longitude',
                            })}
                        </div>
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-3">
                        <Label className="text-xs font-semibold text-muted-foreground uppercase">Min (Required)</Label>
                        <div className="space-y-2">
                            {renderCoordinateInput({
                                field: 'latMin',
                                id: 'lat-min',
                                label: 'Latitude',
                                value: latMin,
                                placeholder: 'e.g., 48.137154',
                                required: true,
                                type: 'latitude',
                            })}
                            {renderCoordinateInput({
                                field: 'lonMin',
                                id: 'lon-min',
                                label: 'Longitude',
                                value: lonMin,
                                placeholder: 'e.g., 11.576124',
                                required: true,
                                type: 'longitude',
                            })}
                        </div>
                    </div>

                    <div className="space-y-3">
                        <Label className="text-xs font-semibold text-muted-foreground uppercase">Max (Optional)</Label>
                        <div className="space-y-2">
                            {renderCoordinateInput({
                                field: 'latMax',
                                id: 'lat-max',
                                label: 'Latitude',
                                value: latMax,
                                placeholder: 'e.g., 48.150000',
                                type: 'latitude',
                            })}
                            {renderCoordinateInput({
                                field: 'lonMax',
                                id: 'lon-max',
                                label: 'Longitude',
                                value: lonMax,
                                placeholder: 'e.g., 11.600000',
                                type: 'longitude',
                            })}
                        </div>
                    </div>
                </div>
            )}

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
