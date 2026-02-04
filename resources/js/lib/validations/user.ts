import { z } from 'zod';

/**
 * Validation schema for creating a new user
 */
export const createUserSchema = z.object({
    name: z
        .string()
        .min(2, 'Name must be at least 2 characters')
        .max(255, 'Name must be less than 255 characters')
        .regex(/^[\p{L}\p{M}\s\-'.]+$/u, 'Name contains invalid characters'),
    email: z.string().email('Please enter a valid email address').max(255, 'Email must be less than 255 characters'),
});

export type CreateUserInput = z.infer<typeof createUserSchema>;

/**
 * Validation schema for login
 */
export const loginSchema = z.object({
    email: z.string().email('Please enter a valid email address'),
    password: z.string().min(1, 'Password is required'),
    remember: z.boolean().optional(),
});

export type LoginInput = z.infer<typeof loginSchema>;

/**
 * Validation schema for password reset request
 */
export const forgotPasswordSchema = z.object({
    email: z.string().email('Please enter a valid email address'),
});

export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>;

/**
 * Validation schema for setting a new password
 */
export const resetPasswordSchema = z
    .object({
        email: z.string().email('Please enter a valid email address'),
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

export type ResetPasswordInput = z.infer<typeof resetPasswordSchema>;

/**
 * Validation schema for confirming password
 */
export const confirmPasswordSchema = z.object({
    password: z.string().min(1, 'Password is required'),
});

export type ConfirmPasswordInput = z.infer<typeof confirmPasswordSchema>;

/**
 * Validation schema for welcome/activation form
 */
export const welcomePasswordSchema = z
    .object({
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

export type WelcomePasswordInput = z.infer<typeof welcomePasswordSchema>;

/**
 * Validation schema for updating password (settings page)
 */
export const updatePasswordSchema = z
    .object({
        current_password: z.string().min(1, 'Current password is required'),
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

export type UpdatePasswordInput = z.infer<typeof updatePasswordSchema>;

/**
 * Validation schema for updating profile (settings page)
 */
export const updateProfileSchema = z.object({
    name: z
        .string()
        .min(2, 'Name must be at least 2 characters')
        .max(255, 'Name must be less than 255 characters')
        .regex(/^[\p{L}\p{M}\s\-'.]+$/u, 'Name contains invalid characters'),
    email: z.string().email('Please enter a valid email address').max(255, 'Email must be less than 255 characters'),
});

export type UpdateProfileInput = z.infer<typeof updateProfileSchema>;

/**
 * Validation schema for deleting account
 */
export const deleteAccountSchema = z.object({
    password: z.string().min(1, 'Password is required to confirm deletion'),
});

export type DeleteAccountInput = z.infer<typeof deleteAccountSchema>;
