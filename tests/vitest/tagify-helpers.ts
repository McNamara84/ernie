export type TagifyAddTags = (
    value: Array<string | Record<string, unknown>> | string,
    clearInput?: boolean,
    silent?: boolean,
) => void;

export type TagifyValue = { value: string; rorId: string | null };

export type TagifyInstance = {
    addTags: TagifyAddTags;
    value: TagifyValue[];
};

export type TagifyEnabledInput = HTMLInputElement & { tagify?: TagifyInstance };

export function getTagifyInstance(input: TagifyEnabledInput): TagifyInstance {
    if (!input.tagify) {
        throw new Error('Expected Tagify instance to be initialised');
    }

    return input.tagify;
}
