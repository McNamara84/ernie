type MapWithUpsert<K, V> = Map<K, V> & {
    getOrInsert?: (key: K, value: V) => V;
    getOrInsertComputed?: (key: K, factory: (key: K) => V) => V;
};

export function mapGetOrInsert<K, V>(map: Map<K, V>, key: K, value: V): V {
    const nativeMap = map as MapWithUpsert<K, V>;
    if (typeof nativeMap.getOrInsert === 'function') {
        return nativeMap.getOrInsert(key, value);
    }

    const existingValue = map.get(key);
    if (existingValue !== undefined || map.has(key)) {
        return existingValue as V;
    }

    map.set(key, value);
    return value;
}

export function mapGetOrInsertComputed<K, V>(map: Map<K, V>, key: K, factory: (key: K) => V): V {
    const nativeMap = map as MapWithUpsert<K, V>;
    if (typeof nativeMap.getOrInsertComputed === 'function') {
        return nativeMap.getOrInsertComputed(key, factory);
    }

    const existingValue = map.get(key);
    if (existingValue !== undefined || map.has(key)) {
        return existingValue as V;
    }

    const nextValue = factory(key);
    map.set(key, nextValue);
    return nextValue;
}