import { describe, expect, it } from 'vitest';

import {
    confirmPasswordSchema,
    createUserSchema,
    deleteAccountSchema,
    forgotPasswordSchema,
    loginSchema,
    resetPasswordSchema,
    updatePasswordSchema,
    updateProfileSchema,
    welcomePasswordSchema,
} from '@/lib/validations/user';

describe('createUserSchema', () => {
    it('accepts valid data', () => {
        const result = createUserSchema.safeParse({ name: 'John Doe', email: 'john@example.com' });
        expect(result.success).toBe(true);
    });

    it('rejects short name', () => {
        const result = createUserSchema.safeParse({ name: 'J', email: 'john@example.com' });
        expect(result.success).toBe(false);
    });

    it('rejects invalid email', () => {
        const result = createUserSchema.safeParse({ name: 'John Doe', email: 'not-an-email' });
        expect(result.success).toBe(false);
    });

    it('rejects name with invalid characters', () => {
        const result = createUserSchema.safeParse({ name: 'John<script>', email: 'john@example.com' });
        expect(result.success).toBe(false);
    });
});

describe('loginSchema', () => {
    it('accepts valid login data', () => {
        const result = loginSchema.safeParse({ email: 'user@example.com', password: 'secret' });
        expect(result.success).toBe(true);
    });

    it('rejects empty password', () => {
        const result = loginSchema.safeParse({ email: 'user@example.com', password: '' });
        expect(result.success).toBe(false);
    });
});

describe('forgotPasswordSchema', () => {
    it('accepts valid email', () => {
        expect(forgotPasswordSchema.safeParse({ email: 'user@example.com' }).success).toBe(true);
    });

    it('rejects invalid email', () => {
        expect(forgotPasswordSchema.safeParse({ email: 'invalid' }).success).toBe(false);
    });
});

describe('resetPasswordSchema', () => {
    it('accepts matching passwords', () => {
        const result = resetPasswordSchema.safeParse({
            email: 'user@example.com',
            password: 'newpassword',
            password_confirmation: 'newpassword',
        });
        expect(result.success).toBe(true);
    });

    it('rejects mismatched passwords', () => {
        const result = resetPasswordSchema.safeParse({
            email: 'user@example.com',
            password: 'newpassword',
            password_confirmation: 'different',
        });
        expect(result.success).toBe(false);
    });

    it('rejects short password', () => {
        const result = resetPasswordSchema.safeParse({
            email: 'user@example.com',
            password: 'short',
            password_confirmation: 'short',
        });
        expect(result.success).toBe(false);
    });
});

describe('confirmPasswordSchema', () => {
    it('accepts non-empty password', () => {
        expect(confirmPasswordSchema.safeParse({ password: 'secret' }).success).toBe(true);
    });

    it('rejects empty password', () => {
        expect(confirmPasswordSchema.safeParse({ password: '' }).success).toBe(false);
    });
});

describe('welcomePasswordSchema', () => {
    it('accepts matching passwords', () => {
        const result = welcomePasswordSchema.safeParse({
            password: 'newpassword',
            password_confirmation: 'newpassword',
        });
        expect(result.success).toBe(true);
    });

    it('rejects mismatched passwords', () => {
        const result = welcomePasswordSchema.safeParse({
            password: 'newpassword',
            password_confirmation: 'different',
        });
        expect(result.success).toBe(false);
    });
});

describe('updatePasswordSchema', () => {
    it('accepts valid password change', () => {
        const result = updatePasswordSchema.safeParse({
            current_password: 'oldpassword',
            password: 'newpassword',
            password_confirmation: 'newpassword',
        });
        expect(result.success).toBe(true);
    });

    it('rejects empty current password', () => {
        const result = updatePasswordSchema.safeParse({
            current_password: '',
            password: 'newpassword',
            password_confirmation: 'newpassword',
        });
        expect(result.success).toBe(false);
    });
});

describe('updateProfileSchema', () => {
    it('accepts valid profile data', () => {
        const result = updateProfileSchema.safeParse({ name: 'Jane Doe', email: 'jane@example.com' });
        expect(result.success).toBe(true);
    });

    it('accepts names with unicode characters', () => {
        const result = updateProfileSchema.safeParse({ name: 'José García-López', email: 'jose@example.com' });
        expect(result.success).toBe(true);
    });
});

describe('deleteAccountSchema', () => {
    it('accepts non-empty password', () => {
        expect(deleteAccountSchema.safeParse({ password: 'confirmdelete' }).success).toBe(true);
    });

    it('rejects empty password', () => {
        expect(deleteAccountSchema.safeParse({ password: '' }).success).toBe(false);
    });
});
