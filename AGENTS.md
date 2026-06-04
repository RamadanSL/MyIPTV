# Agent Notes

- Do not add hardcoded public IPTV/M3U/M3U8 playlist URLs to the codebase.
- Keep `public_playlist_registry_entries()` empty unless the user explicitly asks to restore a built-in registry.
- New playlist sources must come from user input, uploaded/local M3U text, environment/config files, or user-managed discovery/search sources.
- Background jobs must refresh existing user-managed sources only; they must not silently seed public playlists.
- Keep direct playlist reading separate from discovery/web search. Adding an M3U/M3U8 URL must fetch and parse that URL directly; it must not trigger web search.
- Do not reuse ordinary saved playlist URLs as external search/discovery sources unless the user explicitly asks for that behavior.
