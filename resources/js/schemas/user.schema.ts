/**
 * User Zod Schemas
 *
 * Validation schemas for user-related forms (settings, admin).
 */

import { z } from 'zod';

// =============================================================================
// User Roles
// =============================================================================

export const userRoles = ['beginner', 'curator', 'group_leader', 'admin'] as const;

export const userRoleSchema = z.enum(userRoles);

export type UserRole = z.infer<typeof userRoleSchema>;

// =============================================================================
// Login Schema
// =============================================================================

export const loginSchema = z.object({
    email: z.string().email('Invalid email address'),
    password: z.string().min(1, 'Password is required'),
    remember: z.boolean().default(false),
});

export type LoginFormData = z.infer<typeof loginSchema>;

// =============================================================================
// Registration Schema
// =============================================================================

export const registrationSchema = z
    .object({
        name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
        email: z.string().email('Invalid email address'),
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

export type RegistrationFormData = z.infer<typeof registrationSchema>;

// =============================================================================
// Password Change Schema
// =============================================================================

export const passwordChangeSchema = z
    .object({
        current_password: z.string().min(1, 'Current password is required'),
        password: z.string().min(8, 'New password must be at least 8 characters'),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

export type PasswordChangeFormData = z.infer<typeof passwordChangeSchema>;

// =============================================================================
// Profile Update Schema
// =============================================================================

export const profileUpdateSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
    email: z.string().email('Invalid email address'),
});

export type ProfileUpdateFormData = z.infer<typeof profileUpdateSchema>;

// =============================================================================
// Forgot Password Schema
// =============================================================================

export const forgotPasswordSchema = z.object({
    email: z.string().email('Invalid email address'),
});

export type ForgotPasswordFormData = z.infer<typeof forgotPasswordSchema>;

// =============================================================================
// Reset Password Schema
// =============================================================================

export const resetPasswordSchema = z
    .object({
        token: z.string(),
        email: z.string().email('Invalid email address'),
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

export type ResetPasswordFormData = z.infer<typeof resetPasswordSchema>;

// =============================================================================
// Add User Schema (Admin)
// =============================================================================

export const addUserSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
    email: z.string().email('Invalid email address'),
    role: userRoleSchema,
});

export type AddUserFormData = z.infer<typeof addUserSchema>;

// =============================================================================
// Edit User Schema (Admin)
// =============================================================================

export const editUserSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
    email: z.string().email('Invalid email address'),
    role: userRoleSchema,
    is_active: z.boolean().default(true),
});

export type EditUserFormData = z.infer<typeof editUserSchema>;
