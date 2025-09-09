import { useState } from 'react';
import InputField from './fields/input-field';
import { SelectField } from './fields/select-field';
import { LANGUAGE_OPTIONS } from '@/constants/languages';
import type { ResourceType } from '@/types';

interface DataCiteFormData {
    doi: string;
    year: string;
    resourceType: string;
    version: string;
    language: string;
}

interface DataCiteFormProps {
    resourceTypes: ResourceType[];
}

export default function DataCiteForm({ resourceTypes }: DataCiteFormProps) {
    const [form, setForm] = useState<DataCiteFormData>({
        doi: '',
        year: '',
        resourceType: '',
        version: '',
        language: '',
    });

    const handleChange = (field: keyof DataCiteFormData, value: string) => {
        setForm((prev) => ({ ...prev, [field]: value }));
    };

    return (
        <form className="space-y-6">
            <div className="grid gap-4 md:grid-cols-5">
                <InputField
                    id="doi"
                    label="DOI"
                    value={form.doi}
                    onChange={(e) => handleChange('doi', e.target.value)}
                    placeholder="10.xxxx/xxxxx"
                />
                <InputField
                    id="year"
                    type="number"
                    label="Year"
                    value={form.year}
                    onChange={(e) => handleChange('year', e.target.value)}
                    placeholder="2024"
                />
                <SelectField
                    id="resourceType"
                    label="Resource Type"
                    value={form.resourceType}
                    onValueChange={(val) => handleChange('resourceType', val)}
                    options={resourceTypes.map((type) => ({
                        value: type.slug,
                        label: type.name,
                    }))}
                />
                <InputField
                    id="version"
                    label="Version"
                    value={form.version}
                    onChange={(e) => handleChange('version', e.target.value)}
                    placeholder="1.0"
                />
                  <SelectField
                      id="language"
                      label="Language of Data"
                      value={form.language}
                      onValueChange={(val) => handleChange('language', val)}
                      options={LANGUAGE_OPTIONS}
                  />
              </div>
          </form>
      );
  }
