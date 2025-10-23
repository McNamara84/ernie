import { Download, QrCode } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useRef } from 'react';

import { Button } from '@/components/ui/button';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * QR code error correction level
 * Higher levels provide more error correction but result in larger QR codes
 */
type ErrorCorrectionLevel = 'L' | 'M' | 'Q' | 'H';

/**
 * Props for QRCodeGenerator component
 */
interface QRCodeGeneratorProps {
    url: string;
    heading?: string;
    size?: number;
    level?: ErrorCorrectionLevel;
    showUrl?: boolean;
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Convert SVG element to PNG and trigger download
 */
function downloadQRCode(svg: SVGSVGElement | null, filename: string): void {
    if (!svg) {
        console.error('QR Code SVG element not found');
        return;
    }

    try {
        // Create canvas
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            throw new Error('Failed to get canvas context');
        }

        // Get SVG dimensions
        const svgData = new XMLSerializer().serializeToString(svg);
        const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(svgBlob);

        // Create image from SVG
        const img = new Image();
        img.onload = () => {
            // Set canvas size with padding
            const padding = 40;
            canvas.width = img.width + padding * 2;
            canvas.height = img.height + padding * 2;

            // Fill white background
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Draw QR code with padding
            ctx.drawImage(img, padding, padding);

            // Convert to PNG and download
            canvas.toBlob((blob) => {
                if (blob) {
                    const pngUrl = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = pngUrl;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(pngUrl);
                }
                URL.revokeObjectURL(url);
            });
        };

        img.onerror = () => {
            console.error('Failed to load QR Code image');
            URL.revokeObjectURL(url);
        };

        img.src = url;
    } catch (error) {
        console.error('QR Code download failed:', error);
    }
}

/**
 * Generate filename for QR code download
 */
function generateFilename(url: string): string {
    try {
        const urlObj = new URL(url);
        const hostname = urlObj.hostname.replace(/\./g, '-');
        const timestamp = new Date().toISOString().slice(0, 10);
        return `qrcode-${hostname}-${timestamp}.png`;
    } catch {
        const timestamp = new Date().toISOString().slice(0, 10);
        return `qrcode-${timestamp}.png`;
    }
}

/**
 * Truncate URL for display
 */
function truncateUrl(url: string, maxLength: number = 50): string {
    if (url.length <= maxLength) {
        return url;
    }
    const start = url.slice(0, maxLength / 2);
    const end = url.slice(-maxLength / 2);
    return `${start}...${end}`;
}

// ============================================================================
// Component
// ============================================================================

/**
 * QRCodeGenerator Component
 *
 * Generates and displays a QR code for a given URL with download functionality.
 * - Uses qrcode.react for SVG-based QR code generation
 * - Configurable size and error correction level
 * - Download as PNG with white background and padding
 * - Optional URL display
 * - Accessible with proper ARIA labels
 * - Supports dark mode
 *
 * @example
 * ```tsx
 * <QRCodeGenerator
 *   url="https://example.com/landing-page/123"
 *   heading="QR Code"
 *   size={200}
 *   level="M"
 *   showUrl={true}
 * />
 * ```
 */
export default function QRCodeGenerator({
    url,
    heading = 'QR Code',
    size = 200,
    level = 'M',
    showUrl = true,
}: QRCodeGeneratorProps) {
    const qrCodeRef = useRef<SVGSVGElement>(null);

    const handleDownload = () => {
        const filename = generateFilename(url);
        downloadQRCode(qrCodeRef.current, filename);
    };

    return (
        <div className="space-y-4">
            {/* Heading */}
            <div className="flex items-center gap-2">
                <QrCode
                    className="size-6 text-gray-600 dark:text-gray-400"
                    aria-hidden="true"
                />
                <h2 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
            </div>

            {/* QR Code Container */}
            <div className="flex flex-col items-center gap-4 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                {/* QR Code */}
                <div
                    className="rounded-lg bg-white p-4"
                    role="img"
                    aria-label="QR code for landing page URL"
                >
                    <QRCodeSVG
                        value={url}
                        size={size}
                        level={level}
                        includeMargin={false}
                        ref={qrCodeRef}
                    />
                </div>

                {/* URL Display */}
                {showUrl && (
                    <div className="w-full text-center">
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            Scan to visit:
                        </p>
                        <a
                            href={url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="break-all text-sm font-mono text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                            title={url}
                        >
                            {truncateUrl(url)}
                        </a>
                    </div>
                )}

                {/* Download Button */}
                <Button
                    onClick={handleDownload}
                    variant="outline"
                    size="sm"
                    className="w-full sm:w-auto"
                    aria-label="Download QR code as PNG"
                >
                    <Download className="mr-2 size-4" aria-hidden="true" />
                    Download as PNG
                </Button>
            </div>

            {/* Help Text */}
            <p className="text-sm text-gray-600 dark:text-gray-400">
                Scan this QR code with a smartphone camera to quickly access this landing page.
                The downloaded PNG includes a white background and is ready for print materials.
            </p>
        </div>
    );
}
