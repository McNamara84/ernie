import { act } from '@testing-library/react';

/** Finish mount effects and their async work inside React's act scope. */
export function settleRender<Args extends unknown[], Result>(render: (...args: Args) => Result) {
    return async (...args: Args): Promise<Result> => {
        let result: Result;
        await act(async () => {
            result = render(...args);
        });
        return result!;
    };
}
