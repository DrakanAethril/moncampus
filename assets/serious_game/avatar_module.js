/**
 * Loads the serious games' shared avatar module once (public/serious-games/shared/avatar.js).
 *
 * It is a classic script that sets globalThis.SGAvatar - the games load it with a plain
 * <script src> from their sandboxed frame - so it is imported by URL rather than through the
 * importmap: the URL is built at run time, which keeps AssetMapper from trying to resolve it.
 */
let loading = null;

export function loadAvatarModule() {
    if (globalThis.SGAvatar) {
        return Promise.resolve(globalThis.SGAvatar);
    }

    loading ??= Promise.all([
        import(new URL('/serious-games/shared/rewards.js', window.location.origin).href).catch(() => null),
        import(new URL('/serious-games/shared/avatar.js', window.location.origin).href),
    ]).then(() => globalThis.SGAvatar);

    return loading;
}
