/**
 * Form Field Wrappers
 *
 * Convenience components that combine shadcn/ui form primitives with
 * common input components for use with react-hook-form.
 *
 * @example
 * import { FormInput, FormSelect, FormTextarea } from '@/components/curation/form-fields';
 *
 * <FormInput
 *   control={form.control}
 *   name="doi"
 *   label="DOI"
 *   placeholder="10.5880/..."
 * />
 */

export { FormCombobox, type FormComboboxProps } from './form-combobox';
export { FormDatePicker, type FormDatePickerProps } from './form-date-picker';
export { FormInput, type FormInputProps } from './form-input';
export { FormSelect, type FormSelectProps } from './form-select';
export { FormTextarea, type FormTextareaProps } from './form-textarea';
