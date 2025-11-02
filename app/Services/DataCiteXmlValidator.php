<?php

namespace App\Services;

use DOMDocument;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Service for validating DataCite XML against the official XSD schema
 *
 * Validates XML against the DataCite Metadata Schema v4.6
 * Schema URL: https://schema.datacite.org/meta/kernel-4.6/metadata.xsd
 */
class DataCiteXmlValidator
{
    /**
     * DataCite XSD schema URL (always use latest online version)
     */
    private const SCHEMA_URL = 'https://schema.datacite.org/meta/kernel-4.6/metadata.xsd';

    /**
     * Validation warnings collected during validation
     *
     * @var array<string>
     */
    private array $warnings = [];

    /**
     * Validate XML against DataCite XSD schema
     *
     * @param  string  $xml  The XML string to validate
     * @return bool True if validation succeeds, false if validation fails (but allows download with warning)
     *
     * @throws Exception If XML is malformed or cannot be parsed
     */
    public function validate(string $xml): bool
    {
        // Reset warnings
        $this->warnings = [];

        // Enable user error handling
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // Parse XML
            $dom = new DOMDocument;
            $loadResult = $dom->loadXML($xml);

            if (! $loadResult) {
                $errors = libxml_get_errors();
                libxml_clear_errors();

                $errorMessages = array_map(
                    fn ($error) => trim($error->message),
                    $errors
                );

                throw new Exception(
                    'XML parsing failed: '.implode('; ', $errorMessages)
                );
            }

            // Validate against XSD schema
            try {
                // Attempt to fetch and validate against the remote schema
                $validationResult = $dom->schemaValidate(self::SCHEMA_URL);
            } catch (\Exception $schemaException) {
                // Handle network/DNS errors when fetching remote schema
                libxml_clear_errors();

                Log::error('Failed to fetch DataCite XSD schema for validation', [
                    'schema_url' => self::SCHEMA_URL,
                    'error' => $schemaException->getMessage(),
                ]);

                $this->warnings[] = sprintf(
                    'Could not validate against remote schema: %s. Network or DNS issue encountered.',
                    $schemaException->getMessage()
                );

                // Return false to indicate validation could not be completed
                // Still allows download with warning
                return false;
            }

            if (! $validationResult) {
                $errors = libxml_get_errors();
                libxml_clear_errors();

                // Collect validation warnings
                foreach ($errors as $error) {
                    $message = trim($error->message);
                    $this->warnings[] = sprintf(
                        'Line %d: %s',
                        $error->line,
                        $message
                    );
                }

                // Log warnings for debugging
                Log::warning('DataCite XML validation failed', [
                    'warnings' => $this->warnings,
                    'schema_url' => self::SCHEMA_URL,
                ]);

                // Return false to indicate validation failure, but don't throw exception
                // This allows download with warning as per requirements
                return false;
            }

            return true;

        } catch (Exception $e) {
            // Re-throw parsing exceptions (malformed XML)
            libxml_clear_errors();
            throw $e;
        } finally {
            // Always restore error handling
            libxml_use_internal_errors(false);
        }
    }

    /**
     * Get validation warnings collected during last validation
     *
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if there are any validation warnings
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * Get a formatted warning message for user display
     */
    public function getFormattedWarningMessage(): ?string
    {
        if (empty($this->warnings)) {
            return null;
        }

        $count = count($this->warnings);
        $message = "XML validation found {$count} warning(s):";

        // Show first 3 warnings
        $displayWarnings = array_slice($this->warnings, 0, 3);
        foreach ($displayWarnings as $warning) {
            $message .= "\n- ".$warning;
        }

        if ($count > 3) {
            $message .= "\n... and ".($count - 3).' more warning(s).';
        }

        $message .= "\n\nThe file will still be downloaded, but may not be fully compliant with DataCite schema v4.6.";

        return $message;
    }
}
