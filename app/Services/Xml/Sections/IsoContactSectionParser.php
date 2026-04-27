<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses ISO 19115 `pointOfContact` elements (gmd namespace) embedded in a
 * DataCite XML envelope to extract email addresses and websites for matching
 * against authors and contact persons.
 */
final readonly class IsoContactSectionParser
{
    /**
     * @return array<string, array{email: string, website: string}>
     *         Key is normalized name "familyname, givenname" (lowercase, trimmed).
     */
    public function parse(XmlReader $reader): array
    {
        $contactInfo = [];

        $pointOfContactElements = $reader
            ->xpathElement('//*[local-name()="identificationInfo"]//*[local-name()="pointOfContact"]/*[local-name()="CI_ResponsibleParty"]')
            ->get();

        foreach ($pointOfContactElements as $element) {
            $content = $element->getContent();

            if (! is_array($content)) {
                continue;
            }

            $individualName = $this->extractCharacterString($content, 'individualName');

            if ($individualName === null || $individualName === '') {
                continue;
            }

            $normalizedName = mb_strtolower(trim($individualName));

            if (isset($contactInfo[$normalizedName])) {
                continue;
            }

            $email = $this->extractEmail($content);
            $website = $this->extractWebsite($content);

            $contactInfo[$normalizedName] = [
                'email' => $email ?? '',
                'website' => $website ?? '',
            ];
        }

        return $contactInfo;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function getChildElement(array $content, string $key): ?Element
    {
        if (! array_key_exists($key, $content)) {
            return null;
        }

        $value = $content[$key];

        return $value instanceof Element ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function extractCharacterString(array $content, string $elementName): ?string
    {
        $element = $this->getChildElement($content, $elementName);

        if ($element === null) {
            return null;
        }

        $innerContent = $element->getContent();

        if (is_string($innerContent)) {
            return trim($innerContent);
        }

        if (is_array($innerContent)) {
            $charString = $this->getChildElement($innerContent, 'gco:CharacterString')
                ?? $this->getChildElement($innerContent, 'CharacterString');

            if ($charString !== null) {
                $value = $charString->getContent();
                if (is_string($value)) {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function extractEmail(array $content): ?string
    {
        $contactInfoElement = $this->getChildElement($content, 'contactInfo');
        if ($contactInfoElement === null) {
            return null;
        }

        $contactInfoContent = $contactInfoElement->getContent();
        if (! is_array($contactInfoContent)) {
            return null;
        }

        $ciContact = $this->getChildElement($contactInfoContent, 'CI_Contact');
        if ($ciContact === null) {
            return null;
        }

        $ciContactContent = $ciContact->getContent();
        if (! is_array($ciContactContent)) {
            return null;
        }

        $address = $this->getChildElement($ciContactContent, 'address');
        if ($address === null) {
            return null;
        }

        $addressContent = $address->getContent();
        if (! is_array($addressContent)) {
            return null;
        }

        $ciAddress = $this->getChildElement($addressContent, 'CI_Address');
        if ($ciAddress === null) {
            return null;
        }

        $ciAddressContent = $ciAddress->getContent();
        if (! is_array($ciAddressContent)) {
            return null;
        }

        return $this->extractCharacterString($ciAddressContent, 'electronicMailAddress');
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function extractWebsite(array $content): ?string
    {
        $contactInfoElement = $this->getChildElement($content, 'contactInfo');
        if ($contactInfoElement === null) {
            return null;
        }

        $contactInfoContent = $contactInfoElement->getContent();
        if (! is_array($contactInfoContent)) {
            return null;
        }

        $ciContact = $this->getChildElement($contactInfoContent, 'CI_Contact');
        if ($ciContact === null) {
            return null;
        }

        $ciContactContent = $ciContact->getContent();
        if (! is_array($ciContactContent)) {
            return null;
        }

        $onlineResource = $this->getChildElement($ciContactContent, 'onlineResource');
        if ($onlineResource === null) {
            return null;
        }

        $onlineResourceContent = $onlineResource->getContent();
        if (! is_array($onlineResourceContent)) {
            return null;
        }

        $ciOnlineResource = $this->getChildElement($onlineResourceContent, 'CI_OnlineResource');
        if ($ciOnlineResource === null) {
            return null;
        }

        $ciOnlineResourceContent = $ciOnlineResource->getContent();
        if (! is_array($ciOnlineResourceContent)) {
            return null;
        }

        $linkage = $this->getChildElement($ciOnlineResourceContent, 'linkage');
        if ($linkage === null) {
            return null;
        }

        $linkageContent = $linkage->getContent();
        if (! is_array($linkageContent)) {
            return null;
        }

        $urlElement = $this->getChildElement($linkageContent, 'URL');
        if ($urlElement === null) {
            return null;
        }

        $url = $urlElement->getContent();

        return is_string($url) ? trim($url) : null;
    }
}
