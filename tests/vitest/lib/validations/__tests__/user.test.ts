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
    it('accepts valid input', () => {
        const result = createUserSchema.safeParse({ name: 'John Doe', email: 'john@example.com' });
        expect(result.success).toBe(true);
    });

    it('rejects short name', () => {
        const result = createUserSchema.safeParse({ name: 'J', email: 'john@example.com' });
        expect(result.success).toBe(false);
    });

    it('rejects invalid email', () => {
        const result = createUserSchema.safeParse({ name: 'John Doe', email: 'not-email' });
        expect(result.success).toBe(false);
    });

    it('rejects name with invalid characters', () => {
        const result = createUserSchema.safeParse({ name: 'John<script>', email: 'john@example.com' });
        expect(result.success).toBe(false);
    });

    it('accepts names with diacritics', () => {
        const result = createUserSchema.safeParse({ name: 'José García', email: 'jose@example.com' });
        expect(result.success).toBe(true);
    });
});

describe('loginSchema', () => {
    it('accepts valid credentials', () => {
        const result = loginSchema.safeParse({ email: 'user@test.com', password: 'secret123' });
        expect(result.success).toBe(true);
    });

    it('rejects empty password', () => {
        const result = loginSchema.safeParse({ email: 'user@test.com', password: '' });
        expect(result.success).toBe(false);
    });

    it('accepts optional remember field', () => {
        const result = loginSchema.safeParse({ email: 'user@test.com', password: 'secret', remember: true });
        expect(result.success).toBe(true);
    });
});

describe('forgotPasswordSchema', () => {
    it('accepts valid email', () => {
        const result = forgotPasswordSchema.safeParse({ email: 'user@test.com' });
        expect(result.success).toBe(true);
    });

    it('rejects invalid email', () => {
        const result = forgotPasswordSchema.safeParse({ email: 'invalid' });
        expect(result.success).toBe(false);
    });
});

describe('resetPasswordSchema', () => {
    it('accepts matching passwords', () => {
        const result = resetPasswordSchema.safeParse({
            email: 'user@test.com',
            password: 'newpassword123',
            password_confirmation: 'newpassword123',
        });
        expect(result.success).toBe(true);
    });

    it('rejects mismatched passwords', () => {
        const result = resetPasswordSchema.safeParse({
            email: 'user@test.com',
            password: 'newpassword123',
            password_confirmation: 'different',
        });
        expect(result.success).toBe(false);
    });

    it('rejects short password', () => {
        const result = resetPasswordSchema.safeParse({
            email: 'user@test.com',
            password: 'short',
            password_confirmation: 'short',
        });
        expect(result.success).toBe(false);
    });
});

describe('confirmPasswordSchema', () => {
    it('accepts non-empty password', () => {
        const result = confirmPasswordSchema.safeParse({ password: 'anything' });
        expect(result.success).toBe(true);
    });

    it('rejects empty password', () => {
        const result = confirmPasswordSchema.safeParse({ password: '' });
        expect(result.success).toBe(false);
    });
});

describe('welcomePasswordSchema', () => {
    it('accepts matching passwords >= 8 chars', () => {
        const result = welcomePasswordSchema.safeParse({
            password: 'welcome123',
            password_confirmation: 'welcome123',
        });
        expect(result.success).toBe(true);
    });

    it('rejects mismatched passwords', () => {
        const result = welcomePasswordSchema.safeParse({
            password: 'welcome123',
            password_confirmation: 'mismatch',
        });
        expect(result.success).toBe(false);
    });
});

describe('updatePasswordSchema', () => {
    it('accepts valid password update', () => {
        const result = updatePasswordSchema.safeParse({
            current_password: 'oldpass',
            password: 'newpassword123',
            password_confirmation: 'newpassword123',
        });
        expect(result.success).toBe(true);
    });

    it('rejects empty current password', () => {
        const result = updatePasswordSchema.safeParse({
            current_password: '',
            password: 'newpassword123',
            password_confirmation: 'newpassword123',
        });
        expect(result.success).toBe(false);
    });

    it('rejects mismatched new passwords', () => {
        const result = updatePasswordSchema.safeParse({
            current_password: 'oldpass',
            password: 'newpassword123',
            password_confirmation: 'different',
        });
        expect(result.success).toBe(false);
    });
});

describe('updateProfileSchema', () => {
    it('accepts valid profile data', () => {
        const result = updateProfileSchema.safeParse({ name: 'Jane Smith', email: 'jane@example.com' });
        expect(result.success).toBe(true);
    });

    it('rejects too-long email', () => {
        const result = updateProfileSchema.safeParse({
            name: 'Jane',
            email: 'a'.repeat(250) + '@x.com',
        });
        expect(result.success).toBe(false);
    });
});

describe('deleteAccountSchema', () => {
    it('accepts non-empty password', () => {
        const result = deleteAccountSchema.safeParse({ password: 'confirm123' });
        expect(result.success).toBe(true);
    });

    it('rejects empty password', () => {
        const result = deleteAccountSchema.safeParse({ password: '' });
        expect(result.success).toBe(false);
    });
});
