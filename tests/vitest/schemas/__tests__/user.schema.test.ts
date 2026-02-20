import { describe, expect, it } from 'vitest';

import {
    addUserSchema,
    editUserSchema,
    forgotPasswordSchema,
    loginSchema,
    registrationSchema,
    resetPasswordSchema,
    userRoleSchema,
} from '@/schemas/user.schema';

describe('User Schemas', () => {
    describe('userRoleSchema', () => {
        it('accepts valid roles', () => {
            expect(userRoleSchema.safeParse('beginner').success).toBe(true);
            expect(userRoleSchema.safeParse('curator').success).toBe(true);
            expect(userRoleSchema.safeParse('group_leader').success).toBe(true);
            expect(userRoleSchema.safeParse('admin').success).toBe(true);
        });

        it('rejects invalid role', () => {
            expect(userRoleSchema.safeParse('superuser').success).toBe(false);
        });
    });

    describe('loginSchema', () => {
        it('accepts valid login data', () => {
            const result = loginSchema.safeParse({ email: 'user@example.com', password: 'secret' });
            expect(result.success).toBe(true);
        });

        it('rejects invalid email', () => {
            expect(loginSchema.safeParse({ email: 'invalid', password: 'secret' }).success).toBe(false);
        });

        it('rejects empty password', () => {
            expect(loginSchema.safeParse({ email: 'user@example.com', password: '' }).success).toBe(false);
        });

        it('defaults remember to false', () => {
            const result = loginSchema.safeParse({ email: 'user@example.com', password: 'secret' });
            if (result.success) expect(result.data.remember).toBe(false);
        });
    });

    describe('registrationSchema', () => {
        it('accepts valid registration', () => {
            const result = registrationSchema.safeParse({
                name: 'Jane Doe',
                email: 'jane@example.com',
                password: 'password123',
                password_confirmation: 'password123',
            });
            expect(result.success).toBe(true);
        });

        it('rejects password mismatch', () => {
            const result = registrationSchema.safeParse({
                name: 'Jane Doe',
                email: 'jane@example.com',
                password: 'password123',
                password_confirmation: 'different',
            });
            expect(result.success).toBe(false);
        });

        it('rejects short password', () => {
            const result = registrationSchema.safeParse({
                name: 'Jane',
                email: 'jane@example.com',
                password: 'short',
                password_confirmation: 'short',
            });
            expect(result.success).toBe(false);
        });
    });

    describe('forgotPasswordSchema', () => {
        it('accepts valid email', () => {
            expect(forgotPasswordSchema.safeParse({ email: 'user@example.com' }).success).toBe(true);
        });

        it('rejects invalid email', () => {
            expect(forgotPasswordSchema.safeParse({ email: 'bad' }).success).toBe(false);
        });
    });

    describe('resetPasswordSchema', () => {
        it('accepts valid reset data', () => {
            const result = resetPasswordSchema.safeParse({
                token: 'abc123',
                email: 'user@example.com',
                password: 'newpassword',
                password_confirmation: 'newpassword',
            });
            expect(result.success).toBe(true);
        });

        it('rejects password mismatch', () => {
            const result = resetPasswordSchema.safeParse({
                token: 'abc123',
                email: 'user@example.com',
                password: 'newpassword',
                password_confirmation: 'different',
            });
            expect(result.success).toBe(false);
        });
    });

    describe('addUserSchema', () => {
        it('accepts valid user data', () => {
            const result = addUserSchema.safeParse({
                name: 'Jane Doe',
                email: 'jane@example.com',
                role: 'curator',
            });
            expect(result.success).toBe(true);
        });

        it('rejects invalid role', () => {
            const result = addUserSchema.safeParse({
                name: 'Jane',
                email: 'jane@example.com',
                role: 'superadmin',
            });
            expect(result.success).toBe(false);
        });
    });

    describe('editUserSchema', () => {
        it('accepts valid edit data', () => {
            const result = editUserSchema.safeParse({
                name: 'Jane Doe',
                email: 'jane@example.com',
                role: 'admin',
                is_active: true,
            });
            expect(result.success).toBe(true);
        });

        it('defaults is_active to true', () => {
            const result = editUserSchema.safeParse({
                name: 'Jane',
                email: 'jane@example.com',
                role: 'beginner',
            });
            if (result.success) expect(result.data.is_active).toBe(true);
        });
    });
});
