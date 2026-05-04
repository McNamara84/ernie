type RegExpConstructorWithEscape = RegExpConstructor & {
    escape?: (value: string) => string;
};

export function escapeForRegExp(value: string): string {
    const nativeEscape = (RegExp as RegExpConstructorWithEscape).escape;
    if (typeof nativeEscape === 'function') {
        return nativeEscape(value);
    }

    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}