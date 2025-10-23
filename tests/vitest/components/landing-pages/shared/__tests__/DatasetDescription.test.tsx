import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import DatasetDescription from '@/components/landing-pages/shared/DatasetDescription';

describe('DatasetDescription', () => {
    const mockResourceWithMultipleTypes = {
        descriptions: [
            {
                id: 1,
                description_type: 'Abstract',
                description:
                    'This dataset contains seismic data from the 2019 earthquake in California. The data was collected using high-precision instruments and processed using advanced algorithms.',
            },
            {
                id: 2,
                description_type: 'Methods',
                description:
                    'Data collection: Seismometers were deployed at 50 locations across the fault line.\nProcessing: Raw data was filtered and analyzed using custom Python scripts.\nQuality control: All measurements were validated against reference stations.',
            },
            {
                id: 3,
                description_type: 'TechnicalInfo',
                description:
                    'Sampling rate: 100 Hz\nInstrument type: Broadband seismometer\nData format: miniSEED',
            },
            {
                id: 4,
                description_type: 'Other',
                description: 'Funding provided by NSF grant #12345.',
            },
        ],
    };

    const mockResourceSingleAbstract = {
        descriptions: [
            {
                description_type: 'Abstract',
                description: 'A comprehensive study of geological formations in the Alps.',
            },
        ],
    };

    const mockResourceLongDescription = {
        descriptions: [
            {
                description_type: 'Abstract',
                description:
                    'This is a very long description that exceeds the maximum length threshold and should be truncated when the expandable prop is set to true. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
            },
        ],
    };

    const mockResourceNoDescriptions = {
        descriptions: [],
    };

    const mockResourceMissingDescriptions = {};

    describe('Rendering', () => {
        it('should render description section with heading', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByRole('heading', { name: /^description$/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(
                <DatasetDescription
                    resource={mockResourceWithMultipleTypes}
                    heading="Dataset Information"
                />,
            );

            expect(screen.getByRole('heading', { name: /dataset information/i })).toBeInTheDocument();
        });

        it('should not render when no descriptions', () => {
            const { container } = render(<DatasetDescription resource={mockResourceNoDescriptions} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when descriptions property missing', () => {
            const { container } = render(
                <DatasetDescription resource={mockResourceMissingDescriptions} />,
            );

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Description Types Display', () => {
        it('should display all description types', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByRole('heading', { name: /^abstract$/i })).toBeInTheDocument();
            expect(screen.getByRole('heading', { name: /methods/i })).toBeInTheDocument();
            expect(screen.getByRole('heading', { name: /technical information/i })).toBeInTheDocument();
            expect(screen.getByRole('heading', { name: /additional information/i })).toBeInTheDocument();
        });

        it('should not show type heading for single description type', () => {
            render(<DatasetDescription resource={mockResourceSingleAbstract} />);

            // Main heading should be present
            expect(screen.getByRole('heading', { name: /^description$/i })).toBeInTheDocument();

            // But not the "Abstract" subheading (since there's only one type)
            const headings = screen.getAllByRole('heading');
            expect(headings.length).toBe(1);
        });

        it('should show type headings for multiple description types', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const headings = screen.getAllByRole('heading');
            expect(headings.length).toBeGreaterThan(1); // Main heading + type headings
        });
    });

    describe('Description Content', () => {
        it('should display description text', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            expect(
                screen.getByText(/This dataset contains seismic data from the 2019 earthquake/),
            ).toBeInTheDocument();
        });

        it('should preserve line breaks in description', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const methodsText = screen.getByText(/Data collection: Seismometers/);
            expect(methodsText).toBeInTheDocument();
            expect(methodsText.className).toContain('whitespace-pre-wrap');
        });

        it('should handle multiline descriptions', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            expect(screen.getByText(/Data collection: Seismometers/)).toBeInTheDocument();
            expect(screen.getByText(/Data collection: Seismometers/)).toHaveClass('whitespace-pre-wrap');
        });
    });

    describe('Priority Ordering', () => {
        it('should order description types by default priority', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const headings = screen.getAllByRole('heading', { level: 3 });
            const headingTexts = headings.map((h) => h.textContent);

            // Default priority: Abstract, Methods, TechnicalInfo, Other
            expect(headingTexts[0]).toMatch(/abstract/i);
            expect(headingTexts[1]).toMatch(/methods/i);
            expect(headingTexts[2]).toMatch(/technical information/i);
            expect(headingTexts[3]).toMatch(/additional information/i);
        });

        it('should respect custom priority order', () => {
            render(
                <DatasetDescription
                    resource={mockResourceWithMultipleTypes}
                    priorityTypes={['Other', 'TechnicalInfo', 'Methods', 'Abstract']}
                />,
            );

            const headings = screen.getAllByRole('heading', { level: 3 });
            const headingTexts = headings.map((h) => h.textContent);

            expect(headingTexts[0]).toMatch(/additional information/i);
            expect(headingTexts[1]).toMatch(/technical information/i);
            expect(headingTexts[2]).toMatch(/methods/i);
            expect(headingTexts[3]).toMatch(/abstract/i);
        });
    });

    describe('Type Colors', () => {
        it('should apply blue color to Abstract', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const blueIcon = container.querySelector('.text-blue-600');
            expect(blueIcon).toBeInTheDocument();
        });

        it('should apply green color to Methods', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const greenIcon = container.querySelector('.text-green-600');
            expect(greenIcon).toBeInTheDocument();
        });

        it('should apply purple color to TechnicalInfo', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const purpleIcon = container.querySelector('.text-purple-600');
            expect(purpleIcon).toBeInTheDocument();
        });

        it('should apply gray color to Other', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const grayIcon = container.querySelector('.text-gray-600');
            expect(grayIcon).toBeInTheDocument();
        });
    });

    describe('Expandable and Truncation', () => {
        it('should not truncate by default', () => {
            render(<DatasetDescription resource={mockResourceLongDescription} />);

            const longText = screen.getByText(/This is a very long description/);
            expect(longText.textContent).toContain('reprehenderit in voluptate');
        });

        it('should truncate when expandable=true and maxLength is set', () => {
            render(
                <DatasetDescription
                    resource={mockResourceLongDescription}
                    expandable={true}
                    maxLength={100}
                />,
            );

            const truncatedText = screen.getByText(/This is a very long description/);
            expect(truncatedText.textContent).toMatch(/\.\.\.$/);
            expect(truncatedText.textContent?.length).toBeLessThan(150);
        });

        it('should show truncation notice when text is truncated', () => {
            render(
                <DatasetDescription
                    resource={mockResourceLongDescription}
                    expandable={true}
                    maxLength={100}
                />,
            );

            expect(
                screen.getByText(/Text truncated. Full description available in metadata export./),
            ).toBeInTheDocument();
        });

        it('should not show truncation notice when text is not truncated', () => {
            render(
                <DatasetDescription
                    resource={mockResourceSingleAbstract}
                    expandable={true}
                    maxLength={1000}
                />,
            );

            expect(
                screen.queryByText(/Text truncated. Full description available/),
            ).not.toBeInTheDocument();
        });

        it('should not truncate when maxLength is 0', () => {
            render(
                <DatasetDescription
                    resource={mockResourceLongDescription}
                    expandable={true}
                    maxLength={0}
                />,
            );

            const fullText = screen.getByText(/This is a very long description/);
            expect(fullText.textContent).not.toMatch(/\.\.\.$/);
        });
    });

    describe('Multiple Descriptions of Same Type', () => {
        it('should display all descriptions of the same type', () => {
            const resourceWithMultipleSameType = {
                descriptions: [
                    {
                        description_type: 'Abstract',
                        description: 'First abstract paragraph.',
                    },
                    {
                        description_type: 'Abstract',
                        description: 'Second abstract paragraph.',
                    },
                ],
            };

            render(<DatasetDescription resource={resourceWithMultipleSameType} />);

            expect(screen.getByText('First abstract paragraph.')).toBeInTheDocument();
            expect(screen.getByText('Second abstract paragraph.')).toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('should handle descriptions without IDs using index as key', () => {
            const resourceWithoutIds = {
                descriptions: [
                    { description_type: 'Abstract', description: 'Description 1' },
                    { description_type: 'Methods', description: 'Description 2' },
                ],
            };

            render(<DatasetDescription resource={resourceWithoutIds} />);

            expect(screen.getByText('Description 1')).toBeInTheDocument();
            expect(screen.getByText('Description 2')).toBeInTheDocument();
        });

        it('should handle very long single-line description', () => {
            const resourceWithLongLine = {
                descriptions: [
                    {
                        description_type: 'Abstract',
                        description: 'A'.repeat(500),
                    },
                ],
            };

            render(<DatasetDescription resource={resourceWithLongLine} />);

            const longText = screen.getByText(/^A+$/);
            expect(longText).toBeInTheDocument();
        });

        it('should handle empty description text', () => {
            const resourceWithEmpty = {
                descriptions: [
                    {
                        description_type: 'Abstract',
                        description: '',
                    },
                ],
            };

            const { container } = render(<DatasetDescription resource={resourceWithEmpty} />);

            // Should still render the container
            expect(container.querySelector('.prose')).toBeInTheDocument();
        });

        it('should handle unknown description type', () => {
            const resourceWithUnknownType = {
                descriptions: [
                    {
                        description_type: 'CustomType',
                        description: 'Custom description text',
                    },
                ],
            };

            render(<DatasetDescription resource={resourceWithUnknownType} />);

            expect(screen.getByText('CustomType')).toBeInTheDocument();
            expect(screen.getByText('Custom description text')).toBeInTheDocument();
        });

        it('should handle special characters in description', () => {
            const resourceWithSpecialChars = {
                descriptions: [
                    {
                        description_type: 'Abstract',
                        description: 'Temperature: 20°C, CO₂ concentration: 400 ppm, pH: 7.0 ± 0.1',
                    },
                ],
            };

            render(<DatasetDescription resource={resourceWithSpecialChars} />);

            expect(
                screen.getByText(/Temperature: 20°C, CO₂ concentration: 400 ppm/),
            ).toBeInTheDocument();
        });

        it('should handle all DataCite description types', () => {
            const resourceWithAllTypes = {
                descriptions: [
                    { description_type: 'Abstract', description: 'Abstract text' },
                    { description_type: 'Methods', description: 'Methods text' },
                    { description_type: 'TechnicalInfo', description: 'Technical text' },
                    { description_type: 'SeriesInformation', description: 'Series text' },
                    { description_type: 'TableOfContents', description: 'TOC text' },
                    { description_type: 'Other', description: 'Other text' },
                ],
            };

            render(<DatasetDescription resource={resourceWithAllTypes} />);

            expect(screen.getByText('Abstract text')).toBeInTheDocument();
            expect(screen.getByText('Methods text')).toBeInTheDocument();
            expect(screen.getByText('Technical text')).toBeInTheDocument();
            expect(screen.getByText('Series text')).toBeInTheDocument();
            expect(screen.getByText('TOC text')).toBeInTheDocument();
            expect(screen.getByText('Other text')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Description');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <DatasetDescription
                    resource={mockResourceWithMultipleTypes}
                    heading="Dataset Details"
                />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Dataset Details');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });

        it('should use proper heading hierarchy', () => {
            render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const h2 = screen.getByRole('heading', { name: /^description$/i, level: 2 });
            expect(h2).toBeInTheDocument();

            const h3s = screen.getAllByRole('heading', { level: 3 });
            expect(h3s.length).toBe(4); // 4 description types
        });

        it('should use semantic prose styling for content', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const prose = container.querySelector('.prose');
            expect(prose).toBeInTheDocument();
        });
    });

    describe('Dark Mode Support', () => {
        it('should have dark mode classes for description boxes', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const darkBox = container.querySelector('.dark\\:bg-gray-800');
            expect(darkBox).toBeInTheDocument();
        });

        it('should have dark mode classes for text', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const darkText = container.querySelector('.dark\\:text-gray-300');
            expect(darkText).toBeInTheDocument();
        });

        it('should have dark mode classes for prose', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const darkProse = container.querySelector('.dark\\:prose-invert');
            expect(darkProse).toBeInTheDocument();
        });
    });

    describe('Layout and Styling', () => {
        it('should use rounded-lg border for description boxes', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const boxes = container.querySelectorAll('.rounded-lg.border');
            expect(boxes.length).toBe(4); // 4 descriptions
        });

        it('should use prose styling for better typography', () => {
            const { container } = render(<DatasetDescription resource={mockResourceWithMultipleTypes} />);

            const proseElements = container.querySelectorAll('.prose');
            expect(proseElements.length).toBe(4); // One per description
        });
    });
});
