import argparse
import json
import mimetypes
import os
import sys
import time
import zipfile
from datetime import datetime, timezone
from pathlib import Path

import yt_dlp


def ok(data):
    print(json.dumps({"ok": True, "data": data}, ensure_ascii=False))
    sys.exit(0)


def fail(message):
    print(json.dumps({"ok": False, "error": str(message)}, ensure_ascii=False))
    sys.exit(1)


def iso_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_json(path: Path | None, payload: dict):
    if path is None:
        return

    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_suffix(path.suffix + ".tmp")
    temp_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    temp_path.replace(path)


def append_jsonl(path: Path | None, payload: dict):
    if path is None:
        return

    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False) + "\n")


def clamp_percent(value):
    if value is None:
        return None

    try:
        return max(0.0, min(100.0, float(value)))
    except (TypeError, ValueError):
        return None


def parse_percent(value):
    if value in (None, ""):
        return None

    if isinstance(value, (int, float)):
        return clamp_percent(value)

    text = str(value).strip().replace("%", "")
    try:
        return clamp_percent(float(text))
    except ValueError:
        return None


class ProgressReporter:
    def __init__(self, snapshot_path: str | None = None, log_path: str | None = None):
        self.snapshot_path = Path(snapshot_path) if snapshot_path else None
        self.log_path = Path(log_path) if log_path else None
        self.started_at = time.time()
        self.last_percent = None
        self.last_log_at = 0.0
        self.last_phase = None
        self.last_message = None

    def _elapsed(self):
        return max(0, int(time.time() - self.started_at))

    def snapshot(self, payload: dict):
        normalized = {
            "updated_at": iso_now(),
            "elapsed_sec": int(payload.get("elapsed_sec") or self._elapsed()),
            **payload,
        }
        write_json(self.snapshot_path, normalized)

    def log(self, event: str, **payload):
        append_jsonl(
            self.log_path,
            {
                "ts": iso_now(),
                "event": event,
                **payload,
            },
        )

    def mark(self, *, event: str = "status", log_event: bool = True, **payload):
        phase = payload.get("phase")
        message = payload.get("message")
        percent = clamp_percent(payload.get("percent"))

        if percent is not None:
            payload["percent"] = percent

        self.snapshot(payload)

        if log_event:
            self.log(event, **payload)

        self.last_phase = phase
        self.last_message = message
        if percent is not None:
            self.last_percent = percent
            self.last_log_at = time.time()

    def progress_hook(self, data):
        status = str(data.get("status") or "")
        info = data.get("info_dict") or {}
        total = data.get("total_bytes") or data.get("total_bytes_estimate")
        downloaded = data.get("downloaded_bytes") or 0
        speed = data.get("speed")
        eta = data.get("eta")
        percent = None

        if total:
            percent = clamp_percent((float(downloaded) / float(total)) * 100.0)
        if percent is None:
            percent = parse_percent(data.get("_percent_str"))

        filename = data.get("filename") or data.get("tmpfilename") or ""
        title = info.get("title") or Path(filename).name or "Download"
        playlist_index = info.get("playlist_index")
        playlist_count = info.get("playlist_count") or info.get("n_entries")

        if status == "finished":
            message = "Download finished, processing output."
            phase = "processing"
            percent = 100.0
        else:
            message = "Downloading media."
            phase = "downloading"

        payload = {
            "status": status or "running",
            "phase": phase,
            "message": message,
            "title": title,
            "file_name": Path(filename).name if filename else None,
            "percent": percent,
            "downloaded_bytes": int(downloaded or 0),
            "total_bytes": int(total) if total else None,
            "speed_bps": float(speed) if speed else None,
            "eta_sec": int(eta) if eta else None,
            "elapsed_sec": int(data.get("elapsed") or self._elapsed()),
            "playlist_index": int(playlist_index) if playlist_index else None,
            "playlist_count": int(playlist_count) if playlist_count else None,
        }

        self.snapshot(payload)

        should_log = False
        now = time.time()

        if phase != self.last_phase or message != self.last_message:
            should_log = True
        elif percent is not None and (self.last_percent is None or abs(percent - self.last_percent) >= 5):
            should_log = True
        elif now - self.last_log_at >= 8:
            should_log = True

        if should_log:
            self.log("progress", **payload)
            self.last_phase = phase
            self.last_message = message
            self.last_percent = percent
            self.last_log_at = now

    def postprocessor_hook(self, data):
        status = str(data.get("status") or "")
        postprocessor = str(data.get("postprocessor") or "postprocessor")
        info = data.get("info_dict") or {}
        payload = {
            "status": "postprocessing",
            "phase": "processing",
            "message": f"{postprocessor} {status}".strip(),
            "title": info.get("title") or "Download",
            "percent": self.last_percent if self.last_percent is not None else 99.0,
        }

        self.mark(event="postprocess", **payload)

    def fail(self, message: str):
        self.mark(
            event="failed",
            status="failed",
            phase="failed",
            message=str(message),
            percent=self.last_percent if self.last_percent is not None else 100.0,
        )


def get_info(url: str):
    ydl_opts = {
        "quiet": True,
        "skip_download": True,
        "extract_flat": False,
        "noplaylist": False,
        "no_warnings": True,
    }
    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        return ydl.extract_info(url, download=False)


def get_preview_info(url: str):
    wants_playlist = "list=" in url or "/playlist" in url

    ydl_opts = {
        "quiet": True,
        "skip_download": True,
        "noplaylist": False,
        "no_warnings": True,
        "extract_flat": "in_playlist" if wants_playlist else False,
    }

    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        return ydl.extract_info(url, download=False)


def sanitize_filename(name: str) -> str:
    return yt_dlp.utils.sanitize_filename(name or "download", restricted=False)


def preview_url(url: str):
    info = get_preview_info(url)

    if "entries" in info and info.get("_type") == "playlist":
        entries = [e for e in info.get("entries", []) if e]
        thumb = info.get("thumbnail")
        if not thumb and entries:
            thumb = entries[0].get("thumbnail")

        total_duration = 0
        billable_minutes = 0

        for entry in entries:
            duration = int(entry.get("duration") or 0)
            total_duration += duration
            billable_minutes += max(1, int((duration + 59) // 60)) if duration > 0 else 1

        data = {
            "type": "playlist",
            "title": info.get("title") or "Playlist",
            "uploader": info.get("uploader") or info.get("channel") or "",
            "thumbnail": thumb or "",
            "duration_sec": total_duration,
            "billable_minutes": billable_minutes,
            "entries_count": len(entries),
            "webpage_url": info.get("webpage_url") or url,
            "entries": [
                {
                    "title": e.get("title") or "Untitled",
                    "id": e.get("id"),
                    "thumbnail": e.get("thumbnail"),
                    "duration_sec": int(e.get("duration") or 0),
                }
                for e in entries[:25]
            ],
        }
        return ok(data)

    if "entries" in info and info["entries"]:
        first = next((e for e in info["entries"] if e), None)
        if first:
            info = first

    data = {
        "type": "video",
        "title": info.get("title") or "Untitled",
        "uploader": info.get("uploader") or info.get("channel") or "",
        "thumbnail": info.get("thumbnail") or "",
        "duration_sec": int(info.get("duration") or 0),
        "billable_minutes": max(1, int(((int(info.get("duration") or 0)) + 59) // 60)) if int(info.get("duration") or 0) > 0 else 1,
        "entries_count": None,
        "webpage_url": info.get("webpage_url") or url,
        "entries": [],
    }
    return ok(data)


def newest_file_with_ext(outdir: Path, ext: str):
    files = list(outdir.glob(f"*.{ext}"))
    if not files:
        return None
    return max(files, key=lambda p: p.stat().st_mtime)


def download_audio(url: str, fmt: str, outdir: Path, reporter: ProgressReporter | None = None):
    preferred = "192"
    if fmt == "wav":
        preferred = "256"

    ydl_opts = {
        "format": "bestaudio/best",
        "noplaylist": True,
        "outtmpl": str(outdir / "%(title)s.%(ext)s"),
        "postprocessors": [{
            "key": "FFmpegExtractAudio",
            "preferredcodec": fmt,
            "preferredquality": preferred,
        }],
        "quiet": True,
        "noprogress": True,
        "no_warnings": True,
    }

    if reporter is not None:
        ydl_opts["progress_hooks"] = [reporter.progress_hook]
        ydl_opts["postprocessor_hooks"] = [reporter.postprocessor_hook]
        reporter.mark(
            event="start",
            status="running",
            phase="initializing",
            message=f"Starting {fmt.upper()} download.",
            percent=1,
        )

    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        info = ydl.extract_info(url, download=True)

    final_file = newest_file_with_ext(outdir, fmt)
    if not final_file:
        raise RuntimeError(f"No {fmt} file was created.")

    mime = mimetypes.guess_type(final_file.name)[0] or "application/octet-stream"

    if reporter is not None:
        reporter.mark(
            event="completed",
            status="completed",
            phase="completed",
            message="Audio download ready.",
            percent=100,
            file_name=final_file.name,
        )

    return {
        "file_path": str(final_file),
        "file_name": final_file.name,
        "mime": mime,
        "meta": {
            "title": info.get("title"),
            "uploader": info.get("uploader") or info.get("channel"),
        },
    }


def quality_to_format(quality: str):
    mapping = {
        "p480": "bestvideo[height<=480]+bestaudio/best",
        "p720": "bestvideo[height<=720]+bestaudio/best",
        "p1080": "bestvideo[height<=1080]+bestaudio/best",
        "p4k": "bestvideo[height<=2160]+bestaudio/best",
    }
    return mapping.get(quality, mapping["p720"])


def download_video(url: str, quality: str, outdir: Path, reporter: ProgressReporter | None = None):
    ydl_opts = {
        "format": quality_to_format(quality),
        "noplaylist": True,
        "outtmpl": str(outdir / "%(title)s.%(ext)s"),
        "merge_output_format": "mp4",
        "quiet": True,
        "noprogress": True,
        "no_warnings": True,
    }

    if reporter is not None:
        ydl_opts["progress_hooks"] = [reporter.progress_hook]
        ydl_opts["postprocessor_hooks"] = [reporter.postprocessor_hook]
        reporter.mark(
            event="start",
            status="running",
            phase="initializing",
            message=f"Starting {quality.upper()} video download.",
            percent=1,
        )

    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        info = ydl.extract_info(url, download=True)

    final_file = newest_file_with_ext(outdir, "mp4")
    if not final_file:
        raise RuntimeError("No MP4 file was created.")

    mime = "video/mp4"

    if reporter is not None:
        reporter.mark(
            event="completed",
            status="completed",
            phase="completed",
            message="Video download ready.",
            percent=100,
            file_name=final_file.name,
        )

    return {
        "file_path": str(final_file),
        "file_name": final_file.name,
        "mime": mime,
        "meta": {
            "title": info.get("title"),
            "uploader": info.get("uploader") or info.get("channel"),
        },
    }


def download_playlist(url: str, fmt: str, quality: str, outdir: Path, reporter: ProgressReporter | None = None):
    if fmt not in ("mp3", "wav", "mp4"):
        fmt = "mp3"

    if fmt in ("mp3", "wav"):
        preferred_quality = "256" if fmt == "wav" else "192"
        ydl_opts = {
            "format": "bestaudio/best",
            "noplaylist": False,
            "ignoreerrors": "only_download",
            "outtmpl": str(outdir / "%(playlist_title)s - %(playlist_index)02d - %(title)s.%(ext)s"),
            "postprocessors": [{
                "key": "FFmpegExtractAudio",
                "preferredcodec": fmt,
                "preferredquality": preferred_quality,
            }],
            "quiet": True,
            "noprogress": True,
            "no_warnings": True,
        }
    else:
        ydl_opts = {
            "format": quality_to_format(quality),
            "noplaylist": False,
            "ignoreerrors": "only_download",
            "outtmpl": str(outdir / "%(playlist_title)s - %(playlist_index)02d - %(title)s.%(ext)s"),
            "merge_output_format": "mp4",
            "quiet": True,
            "noprogress": True,
            "no_warnings": True,
        }

    if reporter is not None:
        ydl_opts["progress_hooks"] = [reporter.progress_hook]
        ydl_opts["postprocessor_hooks"] = [reporter.postprocessor_hook]
        reporter.mark(
            event="start",
            status="running",
            phase="initializing",
            message=f"Starting playlist {fmt.upper()} download.",
            percent=1,
        )

    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        info = ydl.extract_info(url, download=True)

    entries = [e for e in info.get("entries", []) if e]
    expected = len(entries)
    if expected == 0:
        raise RuntimeError("No items were successfully downloaded from this playlist.")

    all_files = sorted(
        outdir.glob(f"*.{fmt}"),
        key=lambda p: p.stat().st_mtime,
        reverse=True
    )

    playlist_files = all_files[:expected]
    if not playlist_files:
        raise RuntimeError("No files were created for this playlist.")

    playlist_title = sanitize_filename(info.get("title") or "playlist")
    zip_name = f"{playlist_title}_{fmt}_playlist.zip"
    zip_path = outdir / zip_name

    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zipf:
        for index, fpath in enumerate(playlist_files, start=1):
            if reporter is not None:
                reporter.mark(
                    event="packaging",
                    status="packaging",
                    phase="packaging",
                    message=f"Packaging playlist item {index} of {len(playlist_files)}.",
                    percent=min(99.0, 95.0 + ((index / max(len(playlist_files), 1)) * 4.0)),
                    playlist_index=index,
                    playlist_count=len(playlist_files),
                    file_name=fpath.name,
                )
            zipf.write(fpath, arcname=fpath.name)

    for fpath in playlist_files:
        try:
            fpath.unlink(missing_ok=True)
        except Exception:
            pass

    if reporter is not None:
        reporter.mark(
            event="completed",
            status="completed",
            phase="completed",
            message="Playlist ZIP ready.",
            percent=100,
            file_name=zip_name,
            playlist_count=len(playlist_files),
        )

    return {
        "file_path": str(zip_path),
        "file_name": zip_name,
        "mime": "application/zip",
        "meta": {
            "title": info.get("title") or "Playlist",
            "uploader": info.get("uploader") or info.get("channel"),
            "entries_count": len(playlist_files),
        },
    }


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    p1 = sub.add_parser("preview")
    p1.add_argument("--url", required=True)

    p2 = sub.add_parser("download")
    p2.add_argument("--url", required=True)
    p2.add_argument("--mode", required=True, choices=["audio", "video", "playlist"])
    p2.add_argument("--format", default="mp3")
    p2.add_argument("--quality", default="p720")
    p2.add_argument("--outdir", required=True)
    p2.add_argument("--progress-file")
    p2.add_argument("--progress-log")

    args = parser.parse_args()

    try:
        if args.command == "preview":
            preview_url(args.url)

        if args.command == "download":
            outdir = Path(args.outdir)
            outdir.mkdir(parents=True, exist_ok=True)
            reporter = ProgressReporter(args.progress_file, args.progress_log)

            if args.mode == "audio":
                return ok(download_audio(args.url, args.format, outdir, reporter))

            if args.mode == "video":
                return ok(download_video(args.url, args.quality, outdir, reporter))

            if args.mode == "playlist":
                playlist_fmt = args.format if args.format in ("mp3", "wav", "mp4") else "mp3"
                return ok(download_playlist(args.url, playlist_fmt, args.quality, outdir, reporter))

    except Exception as e:
        if 'reporter' in locals():
            reporter.fail(str(e))
        fail(str(e))


if __name__ == "__main__":
    main()
