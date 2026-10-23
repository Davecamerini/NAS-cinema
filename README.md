# Cinema

**Version 1.6.4** (`CINEMA_VERSION` in `lib/paths.php`)

Personal movies and TV library for a LAN NAS. Browse posters, resume playback, mark titles watched, fetch artwork from TMDB, and convert MKV/AVI to MP4. No login.

## Requirements

- PHP 8.0+ with `pdo_sqlite`
- `allow_url_fopen` for TMDB posters and info
- NAS path readable (and writable for convert) by the PHP user
- Optional: `ffprobe` for durations, `ffmpeg` with `libx264` to convert MKV/AVI → MP4
- Browser-friendly video: **MP4 / M4V / WebM / OGG / MOV**. MKV and AVI are indexed and can be converted.

## Setup

1. Open `/cinema/`.
2. **Settings**: set NAS root (default `/mnt/NAS/video`).
3. Create `Movies/` and `Series/` under that root (folder names are configurable).
4. Paste a [TMDB API key](https://www.themoviedb.org/settings/api).
5. **Scan library**, then **Fetch posters & info**.

English and Italian UI/TMDB language can be set in Settings. The current version is shown on the Settings page.

## Folder layout

```
/mnt/NAS/video/
  Movies/
    The Matrix (1999)/The Matrix (1999).mp4
    Inception (2010).mp4
  Series/
    Breaking Bad/
      Season 01/
        Breaking Bad - S01E01 - Pilot.mp4
    The Office/
      S01E01.mp4
```

Optional `poster.jpg` next to a title overrides TMDB art.

Episode numbers are read from `S01E02`, `1x02`, or a `Season 01` folder.

## Library

- **Scan library** walks the NAS and shows indexing progress (`done / total`). Existing TMDB matches are kept.
- **Fetch posters & info** fills unmatched titles. It also fetches stills/synopses for new episodes on a show that already has metadata. No need to clear previous meta.
- **Retry failed matches** resets TMDB failures so fetch can try again.
- Live search in the header filters as you type. Clearing the box restores the page you were on.

## Watching

- Poster cards show a green check when watched (92%+ or marked watched) and a yellow play icon while in progress.
- Movies, episodes, and whole series can be marked watched or unwatched.
- Continue watching lists in-progress titles only (started, under 92%).
- **Clear continue watching** removes in-progress rows only. Watched status is kept.
- Player: Space play/pause, arrows skip 10s and change volume. Chrome hides after a few seconds while playing.
- **Subtitles:** enable from the browser’s CC menu.
  - **Sidecar:** `.srt` / `.vtt` next to the video (`Show.S01E01.en.srt`).
  - **Muxed:** text tracks inside MKV/MP4 (SRT/ASS/mov_text/WebVTT) are extracted with ffmpeg on first play and cached under `data/subs/`. Image-based tracks (PGS, VobSub) are not supported — extract a sidecar for those.
  - Requires `ffprobe` + `ffmpeg` on the server.

## Convert to MP4

1. **Scan MKV/AVI** to count files that still need an MP4.
2. **Convert to MP4** encodes (or remuxes) on the server with progress.

After a successful convert, the original MKV/AVI is **deleted**. The MP4 stays in the same folder and the catalog points at it. Failed converts leave the original in place.

Text subtitle tracks (SRT/ASS/mov_text/WebVTT) are remuxed into the MP4 as `mov_text`. Image-based tracks (PGS/VobSub) are skipped.

Stay on Settings while a batch runs. The current ffmpeg process keeps going if you leave, but later files wait until you return and click Convert again.

## Notes

- Catalog is SQLite in `data/cinema.sqlite` (the NAS is not walked on every page).
- Playback uses HTTP Range via `stream.php` (media stays outside the web root).
- Resume and watched flags are stored in SQLite (one viewer).
- Meant for a private LAN or VPN. There is no authentication.

## Versioning

Bump `CINEMA_VERSION` in `lib/paths.php` when you ship a change, then add an entry below. Use [SemVer](https://semver.org/): MAJOR for breaking changes, MINOR for features, PATCH for fixes.

## Changelog

### 1.6.4 — 2026-10-16

**Added**

- Settings storage bar for NAS root (used / free disk space)

### 1.6.3 — 2026-10-14

**Fixed**

- Choosing sort “Title” no longer falls back to the previously saved sort

### 1.6.2 — 2026-10-14

**Added**

- Movies/Series remember hide-watched, sort, and order (cookie, per list)

### 1.6.1 — 2026-10-10

**Changed**

- MKV/AVI → MP4 convert keeps text subtitle tracks muxed as `mov_text` (PGS/VobSub still dropped)

### 1.6.0 — 2026-10-10

**Added**

- Muxed text subtitle tracks extracted via ffmpeg/ffprobe and cached for the player (PGS/VobSub still need sidecars)

### 1.5.0 — 2026-10-10

**Added**

- Sidecar subtitle support (`.srt` / `.vtt` next to the video); SRT is converted to WebVTT for the player

### 1.4.2 — 2026-10-05

**Changed**

- Library scan skips `ffprobe` and DB rewrites when a file’s size and mtime are unchanged
- Scan processes 32 items per batch instead of 6

### 1.4.1 — 2026-09-28

**Added**

- Non-MP4 warning badge next to movie titles, series titles (if any episode isn’t MP4), and episode names

### 1.4.0 — 2026-09-28

**Added**

- Next episode overlay at the end of a series episode, with 10s autoplay and Cancel
- Playing next (button or autoplay) marks the current episode watched

### 1.3.1 — 2026-09-27

**Added**

- Ascending / descending order control next to the sort dropdown

### 1.3.0 — 2026-09-27

**Added**

- Sort dropdown on Movies and Series (title, year, recently added, rating)

### 1.2.0 — 2026-09-25

**Added**

- Hide-watched filter toggle on Movies and Series lists (remembered per list with sort/order)
- Opening a series lands on the first season with unwatched episodes (season 1 if the whole show is watched)

### 1.1.0 — 2026-09-16

**Changed**

- Convert progress uses real file percent, elapsed time, encode speed, and ETA (no longer stuck at 99%)

### 1.0.0 — 2026-09-16

First documented release.

**Added**

- NAS movie and series library with TMDB posters, synopses, cast, and trailers
- Scan with progress; fetch metadata including new episodes on already-matched series
- Continue watching, resume, and watch icons on posters
- Mark watched / unwatched on movies, episodes, and whole series
- Live header search
- MKV/AVI → MP4 convert with progress; original deleted after a successful convert
- English / Italian UI and TMDB language
- Player keyboard controls and auto-hiding chrome
- Clear continue watching without clearing watched status
