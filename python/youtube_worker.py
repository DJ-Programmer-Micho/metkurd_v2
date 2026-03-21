import argparse
import json
import mimetypes
import re
import shutil
import subprocess
import sys
import time
import zipfile
from datetime import datetime, timezone
from pathlib import Path

from pytubefix import Playlist, YouTube


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


def sanitize_filename(name: str) -> str:
    cleaned = re.sub(r'[<>:"/\\|?*\x00-\x1f]+', " ", name or "download").strip().rstrip(".")
    cleaned = re.sub(r"\s+", " ", cleaned)
    return cleaned or "download"


def target_height_for_quality(quality: str) -> int:
    return {
        "p480": 480,
        "p720": 720,
        "p1080": 1080,
        "p4k": 2160,
    }.get((quality or "").lower(), 720)


def resolution_height(stream) -> int:
    resolution = getattr(stream, "resolution", None) or ""
    match = re.match(r"(\d+)p", str(resolution))
    return int(match.group(1)) if match else 0


def stream_filesize(stream) -> int:
    return int(getattr(stream, "filesize", 0) or getattr(stream, "filesize_approx", 0) or 0)


def unique_path(outdir: Path, stem: str, ext: str) -> Path:
    ext = ext.lstrip(".")
    stem = sanitize_filename(stem)
    candidate = outdir / f"{stem}.{ext}"
    counter = 2

    while candidate.exists():
        candidate = outdir / f"{stem}_{counter}.{ext}"
        counter += 1

    return candidate


def ensure_ffmpeg():
    if shutil.which("ffmpeg") is None:
        raise RuntimeError("FFmpeg is required but was not found in PATH.")


def run_ffmpeg(command: list[str], error_prefix: str):
    ensure_ffmpeg()

    result = subprocess.run(command, capture_output=True, text=True)

    if result.returncode != 0:
        stderr = (result.stderr or "").strip()
        stdout = (result.stdout or "").strip()
        message = stderr or stdout or f"{error_prefix} failed."
        raise RuntimeError(f"{error_prefix} failed: {message}")


class ProgressReporter:
    def __init__(self, snapshot_path: str | None = None, log_path: str | None = None):
        self.snapshot_path = Path(snapshot_path) if snapshot_path else None
        self.log_path = Path(log_path) if log_path else None
        self.started_at = time.time()
        self.last_percent = None
        self.last_log_at = 0.0
        self.last_phase = None
        self.last_message = None

    def _elapsed(self) -> int:
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
        percent = clamp_percent(payload.get("percent"))
        if percent is not None:
            payload["percent"] = percent

        self.snapshot(payload)

        if log_event:
            self.log(event, **payload)

        self.last_phase = payload.get("phase")
        self.last_message = payload.get("message")
        if percent is not None:
            self.last_percent = percent
            self.last_log_at = time.time()

    def record_progress(
        self,
        *,
        title: str,
        file_name: str | None,
        downloaded_bytes: int,
        total_bytes: int,
        progress_offset: float,
        progress_span: float,
        message: str,
        phase: str = "downloading",
        playlist_index: int | None = None,
        playlist_count: int | None = None,
    ):
        elapsed = max(time.time() - self.started_at, 0.001)
        speed = float(downloaded_bytes) / elapsed if downloaded_bytes > 0 else None
        eta = int((total_bytes - downloaded_bytes) / speed) if speed and total_bytes > downloaded_bytes else 0
        stage_percent = (float(downloaded_bytes) / float(total_bytes)) * progress_span if total_bytes > 0 else 0.0
        percent = clamp_percent(progress_offset + stage_percent)

        payload = {
            "status": "running",
            "phase": phase,
            "message": message,
            "title": title,
            "file_name": file_name,
            "percent": percent,
            "downloaded_bytes": int(downloaded_bytes),
            "total_bytes": int(total_bytes) if total_bytes else None,
            "speed_bps": speed,
            "eta_sec": eta if eta > 0 else None,
            "elapsed_sec": int(elapsed),
            "playlist_index": playlist_index,
            "playlist_count": playlist_count,
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

    def fail(self, message: str):
        self.mark(
            event="failed",
            status="failed",
            phase="failed",
            message=str(message),
            percent=self.last_percent if self.last_percent is not None else 100.0,
        )


def build_youtube(url: str, on_progress_callback=None) -> YouTube:
    return YouTube(url, on_progress_callback=on_progress_callback)


def billable_minutes(duration_sec: int) -> int:
    return max(1, int((max(0, duration_sec) + 59) // 60)) if duration_sec > 0 else 1


def stream_progress_callback(
    reporter: ProgressReporter | None,
    *,
    title: str,
    file_name: str | None,
    total_bytes: int,
    progress_offset: float,
    progress_span: float,
    message: str,
    playlist_index: int | None = None,
    playlist_count: int | None = None,
):
    def callback(stream, _chunk, bytes_remaining):
        if reporter is None:
            return

        total = total_bytes or stream_filesize(stream)
        if total <= 0:
            return

        downloaded = max(0, total - int(bytes_remaining or 0))

        reporter.record_progress(
            title=title,
            file_name=file_name,
            downloaded_bytes=downloaded,
            total_bytes=total,
            progress_offset=progress_offset,
            progress_span=progress_span,
            message=message,
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

    return callback


def best_audio_stream(yt: YouTube):
    streams = list(yt.streams.filter(only_audio=True))
    streams.sort(key=lambda stream: (stream_filesize(stream), getattr(stream, "abr", "") or ""), reverse=True)
    return streams[0] if streams else None


def best_progressive_stream(yt: YouTube, target_height: int):
    streams = list(yt.streams.filter(progressive=True, subtype="mp4"))
    streams.sort(key=lambda stream: resolution_height(stream), reverse=True)

    for stream in streams:
        if resolution_height(stream) <= target_height:
            return stream

    return streams[0] if streams else None


def best_adaptive_video_stream(yt: YouTube, target_height: int):
    streams = list(yt.streams.filter(adaptive=True, only_video=True, subtype="mp4"))
    streams.sort(key=lambda stream: resolution_height(stream), reverse=True)

    for stream in streams:
        if resolution_height(stream) <= target_height:
            return stream

    return streams[0] if streams else None


def should_use_progressive_stream(progressive, adaptive, target_height: int) -> bool:
    if progressive is None:
        return False

    if adaptive is None:
        return True

    progressive_height = resolution_height(progressive)
    adaptive_height = resolution_height(adaptive)

    if progressive_height <= 0:
        return False

    # 1080p / 4K selections should prefer adaptive MP4 video when available.
    if target_height > 720 and adaptive_height > 0:
        return False

    # If adaptive can hit the requested quality and progressive cannot, use adaptive.
    if adaptive_height >= target_height and progressive_height < target_height:
        return False

    # If adaptive can satisfy a better resolution than progressive, use it.
    if adaptive_height > progressive_height:
        return False

    return True


def download_stream(
    yt: YouTube,
    stream,
    outdir: Path,
    *,
    stem: str,
    reporter: ProgressReporter | None,
    progress_offset: float,
    progress_span: float,
    message: str,
    playlist_index: int | None = None,
    playlist_count: int | None = None,
) -> Path:
    total_bytes = stream_filesize(stream)
    suffix = getattr(stream, "subtype", None) or (getattr(stream, "mime_type", "").split("/")[-1] if getattr(stream, "mime_type", None) else "bin")
    target_path = unique_path(outdir, stem, suffix)

    if reporter is not None:
        yt.register_on_progress_callback(
            stream_progress_callback(
                reporter,
                title=yt.title or "Download",
                file_name=target_path.name,
                total_bytes=total_bytes,
                progress_offset=progress_offset,
                progress_span=progress_span,
                message=message,
                playlist_index=playlist_index,
                playlist_count=playlist_count,
            )
        )

    downloaded = stream.download(output_path=str(outdir), filename=target_path.stem)
    return Path(downloaded)


def convert_audio(source_path: Path, target_path: Path, fmt: str):
    if fmt == "wav":
        command = [
            "ffmpeg",
            "-y",
            "-i",
            str(source_path),
            "-vn",
            "-acodec",
            "pcm_s16le",
            str(target_path),
        ]
    else:
        command = [
            "ffmpeg",
            "-y",
            "-i",
            str(source_path),
            "-vn",
            "-codec:a",
            "libmp3lame",
            "-q:a",
            "2",
            str(target_path),
        ]

    run_ffmpeg(command, f"{fmt.upper()} conversion")


def remux_or_transcode_to_mp4(source_path: Path, target_path: Path):
    command = [
        "ffmpeg",
        "-y",
        "-i",
        str(source_path),
        "-c:v",
        "copy",
        "-c:a",
        "copy",
        str(target_path),
    ]
    run_ffmpeg(command, "MP4 remux")


def merge_video_audio(video_path: Path, audio_path: Path, target_path: Path):
    command = [
        "ffmpeg",
        "-y",
        "-i",
        str(video_path),
        "-i",
        str(audio_path),
        "-c:v",
        "copy",
        "-c:a",
        "aac",
        "-shortest",
        str(target_path),
    ]
    run_ffmpeg(command, "Video merge")


def preview_video(url: str):
    yt = build_youtube(url)

    data = {
        "type": "video",
        "title": yt.title or "Untitled",
        "uploader": yt.author or "",
        "thumbnail": yt.thumbnail_url or "",
        "duration_sec": int(yt.length or 0),
        "billable_minutes": billable_minutes(int(yt.length or 0)),
        "entries_count": None,
        "webpage_url": yt.watch_url or url,
        "entries": [],
    }
    return ok(data)


def preview_playlist(url: str):
    playlist = Playlist(url)
    urls = list(playlist.video_urls)

    if not urls:
        raise RuntimeError("No accessible videos were found in this playlist.")

    entries = []
    total_duration = 0
    total_billable = 0
    uploader = getattr(playlist, "owner", "") or ""
    thumbnail = ""

    for item_url in urls:
        try:
            yt = build_youtube(item_url)
        except Exception:
            continue

        duration = int(yt.length or 0)
        total_duration += duration
        total_billable += billable_minutes(duration)

        if thumbnail == "":
            thumbnail = yt.thumbnail_url or ""

        if uploader == "":
            uploader = yt.author or ""

        if len(entries) < 25:
            entries.append({
                "title": yt.title or "Untitled",
                "id": yt.video_id,
                "thumbnail": yt.thumbnail_url or "",
                "duration_sec": duration,
            })

    if not entries and total_duration == 0:
        raise RuntimeError("Playlist items could not be accessed.")

    data = {
        "type": "playlist",
        "title": getattr(playlist, "title", None) or "Playlist",
        "uploader": uploader,
        "thumbnail": thumbnail,
        "duration_sec": total_duration,
        "billable_minutes": total_billable,
        "entries_count": len(urls),
        "webpage_url": url,
        "entries": entries,
    }
    return ok(data)


def preview_url(
    url: str,
    cookie_browsers: list[str] | None = None,
    cookie_browser_profile: str | None = None,
    cookie_file: str | None = None,
):
    _ = cookie_browsers, cookie_browser_profile, cookie_file

    if "list=" in url or "/playlist" in url:
        return preview_playlist(url)

    return preview_video(url)


def download_audio(
    url: str,
    fmt: str,
    outdir: Path,
    reporter: ProgressReporter | None = None,
    cookie_browsers: list[str] | None = None,
    cookie_browser_profile: str | None = None,
    cookie_file: str | None = None,
    playlist_index: int | None = None,
    playlist_count: int | None = None,
):
    _ = cookie_browsers, cookie_browser_profile, cookie_file

    yt = build_youtube(url)
    stream = best_audio_stream(yt)
    if stream is None:
        raise RuntimeError("No audio stream was found for this video.")

    target_ext = "wav" if fmt == "wav" else "mp3"
    source_message = "Downloading audio stream."
    if playlist_index and playlist_count:
        source_message = f"Downloading playlist item {playlist_index} of {playlist_count}."

    if reporter is not None:
        reporter.mark(
            event="start",
            status="running",
            phase="initializing",
            message=f"Starting {target_ext.upper()} download.",
            percent=1,
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

    source_path = download_stream(
        yt,
        stream,
        outdir,
        stem=sanitize_filename(yt.title or "audio"),
        reporter=reporter,
        progress_offset=2.0,
        progress_span=83.0,
        message=source_message,
        playlist_index=playlist_index,
        playlist_count=playlist_count,
    )

    final_path = unique_path(outdir, sanitize_filename(yt.title or "audio"), target_ext)

    if reporter is not None:
        reporter.mark(
            event="processing",
            status="postprocessing",
            phase="processing",
            message=f"Converting audio to {target_ext.upper()}.",
            percent=92,
            file_name=final_path.name,
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

    convert_audio(source_path, final_path, target_ext)
    source_path.unlink(missing_ok=True)

    mime = mimetypes.guess_type(final_path.name)[0] or "application/octet-stream"

    if reporter is not None:
        reporter.mark(
            event="completed",
            status="completed",
            phase="completed",
            message="Audio download ready.",
            percent=100,
            file_name=final_path.name,
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

    return {
        "file_path": str(final_path),
        "file_name": final_path.name,
        "mime": mime,
        "meta": {
            "title": yt.title,
            "uploader": yt.author,
        },
    }


def download_video(
    url: str,
    quality: str,
    outdir: Path,
    reporter: ProgressReporter | None = None,
    cookie_browsers: list[str] | None = None,
    cookie_browser_profile: str | None = None,
    cookie_file: str | None = None,
    playlist_index: int | None = None,
    playlist_count: int | None = None,
):
    _ = cookie_browsers, cookie_browser_profile, cookie_file

    yt = build_youtube(url)
    target_height = target_height_for_quality(quality)
    title_stem = sanitize_filename(yt.title or "video")

    if reporter is not None:
        reporter.mark(
            event="start",
            status="running",
            phase="initializing",
            message=f"Starting {quality.upper()} video download.",
            percent=1,
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

    progressive = best_progressive_stream(yt, target_height)
    adaptive = best_adaptive_video_stream(yt, target_height)

    if should_use_progressive_stream(progressive, adaptive, target_height):
        source_path = download_stream(
            yt,
            progressive,
            outdir,
            stem=title_stem,
            reporter=reporter,
            progress_offset=2.0,
            progress_span=88.0,
            message="Downloading video stream.",
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

        final_path = unique_path(outdir, title_stem, "mp4")

        if source_path.suffix.lower() == ".mp4":
            if source_path != final_path:
                source_path.replace(final_path)
        else:
            if reporter is not None:
                reporter.mark(
                    event="processing",
                    status="postprocessing",
                    phase="processing",
                    message="Preparing MP4 output.",
                    percent=94,
                    file_name=final_path.name,
                    playlist_index=playlist_index,
                    playlist_count=playlist_count,
                )

            remux_or_transcode_to_mp4(source_path, final_path)
            source_path.unlink(missing_ok=True)
    else:
        video_stream = adaptive
        audio_stream = best_audio_stream(yt)

        if video_stream is None or audio_stream is None:
            raise RuntimeError("Required video/audio streams were not found for this video.")

        video_path = download_stream(
            yt,
            video_stream,
            outdir,
            stem=f"{title_stem}_video",
            reporter=reporter,
            progress_offset=2.0,
            progress_span=43.0,
            message="Downloading video stream.",
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

        audio_path = download_stream(
            yt,
            audio_stream,
            outdir,
            stem=f"{title_stem}_audio",
            reporter=reporter,
            progress_offset=45.0,
            progress_span=43.0,
            message="Downloading audio stream.",
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

        final_path = unique_path(outdir, title_stem, "mp4")

        if reporter is not None:
            reporter.mark(
                event="processing",
                status="postprocessing",
                phase="processing",
                message="Merging video and audio.",
                percent=94,
                file_name=final_path.name,
                playlist_index=playlist_index,
                playlist_count=playlist_count,
            )

        merge_video_audio(video_path, audio_path, final_path)
        video_path.unlink(missing_ok=True)
        audio_path.unlink(missing_ok=True)

    if reporter is not None:
        reporter.mark(
            event="completed",
            status="completed",
            phase="completed",
            message="Video download ready.",
            percent=100,
            file_name=final_path.name,
            playlist_index=playlist_index,
            playlist_count=playlist_count,
        )

    return {
        "file_path": str(final_path),
        "file_name": final_path.name,
        "mime": "video/mp4",
        "meta": {
            "title": yt.title,
            "uploader": yt.author,
        },
    }


def download_playlist(
    url: str,
    fmt: str,
    quality: str,
    outdir: Path,
    reporter: ProgressReporter | None = None,
    cookie_browsers: list[str] | None = None,
    cookie_browser_profile: str | None = None,
    cookie_file: str | None = None,
):
    _ = cookie_browsers, cookie_browser_profile, cookie_file

    playlist = Playlist(url)
    urls = list(playlist.video_urls)

    if not urls:
        raise RuntimeError("No accessible videos were found in this playlist.")

    if reporter is not None:
        reporter.mark(
            event="start",
            status="running",
            phase="initializing",
            message=f"Starting playlist {fmt.upper()} download.",
            percent=1,
            playlist_count=len(urls),
        )

    downloaded_files = []
    first_title = getattr(playlist, "title", None) or "Playlist"
    first_uploader = getattr(playlist, "owner", None) or ""

    for index, item_url in enumerate(urls, start=1):
        try:
            if fmt in ("mp3", "wav"):
                item = download_audio(
                    item_url,
                    fmt,
                    outdir,
                    reporter,
                    playlist_index=index,
                    playlist_count=len(urls),
                )
            else:
                item = download_video(
                    item_url,
                    quality,
                    outdir,
                    reporter,
                    playlist_index=index,
                    playlist_count=len(urls),
                )

            downloaded_files.append(Path(item["file_path"]))

            if first_uploader == "":
                first_uploader = item.get("meta", {}).get("uploader") or ""
        except Exception as exc:
            if reporter is not None:
                reporter.log(
                    "playlist_skip",
                    phase="failed",
                    message=f"Skipped playlist item {index}: {exc}",
                    playlist_index=index,
                    playlist_count=len(urls),
                )
            continue

    if not downloaded_files:
        raise RuntimeError("No items were successfully downloaded from this playlist.")

    zip_name = f"{sanitize_filename(first_title)}_{fmt}_playlist.zip"
    zip_path = unique_path(outdir, zip_name.rsplit(".", 1)[0], "zip")

    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as archive:
        for index, file_path in enumerate(downloaded_files, start=1):
            if reporter is not None:
                reporter.mark(
                    event="packaging",
                    status="packaging",
                    phase="packaging",
                    message=f"Packaging playlist item {index} of {len(downloaded_files)}.",
                    percent=min(99.0, 95.0 + ((index / max(len(downloaded_files), 1)) * 4.0)),
                    playlist_index=index,
                    playlist_count=len(downloaded_files),
                    file_name=file_path.name,
                )
            archive.write(file_path, arcname=file_path.name)

    for file_path in downloaded_files:
        file_path.unlink(missing_ok=True)

    if reporter is not None:
        reporter.mark(
            event="completed",
            status="completed",
            phase="completed",
            message="Playlist ZIP ready.",
            percent=100,
            file_name=zip_path.name,
            playlist_count=len(downloaded_files),
        )

    return {
        "file_path": str(zip_path),
        "file_name": zip_path.name,
        "mime": "application/zip",
        "meta": {
            "title": first_title,
            "uploader": first_uploader,
            "entries_count": len(downloaded_files),
        },
    }


def add_cookie_args(parser: argparse.ArgumentParser):
    parser.add_argument("--cookies-browser", action="append")
    parser.add_argument("--cookies-browser-profile")
    parser.add_argument("--cookies-file")


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    p1 = sub.add_parser("preview")
    p1.add_argument("--url", required=True)
    add_cookie_args(p1)

    p2 = sub.add_parser("download")
    p2.add_argument("--url", required=True)
    p2.add_argument("--mode", required=True, choices=["audio", "video", "playlist"])
    p2.add_argument("--format", default="mp3")
    p2.add_argument("--quality", default="p720")
    p2.add_argument("--outdir", required=True)
    p2.add_argument("--progress-file")
    p2.add_argument("--progress-log")
    add_cookie_args(p2)

    args = parser.parse_args()

    try:
        if args.command == "preview":
            preview_url(
                args.url,
                cookie_browsers=args.cookies_browser,
                cookie_browser_profile=args.cookies_browser_profile,
                cookie_file=args.cookies_file,
            )

        if args.command == "download":
            outdir = Path(args.outdir)
            outdir.mkdir(parents=True, exist_ok=True)
            reporter = ProgressReporter(args.progress_file, args.progress_log)

            if args.mode == "audio":
                return ok(download_audio(
                    args.url,
                    args.format,
                    outdir,
                    reporter,
                    cookie_browsers=args.cookies_browser,
                    cookie_browser_profile=args.cookies_browser_profile,
                    cookie_file=args.cookies_file,
                ))

            if args.mode == "video":
                return ok(download_video(
                    args.url,
                    args.quality,
                    outdir,
                    reporter,
                    cookie_browsers=args.cookies_browser,
                    cookie_browser_profile=args.cookies_browser_profile,
                    cookie_file=args.cookies_file,
                ))

            if args.mode == "playlist":
                playlist_fmt = args.format if args.format in ("mp3", "wav", "mp4") else "mp3"
                return ok(download_playlist(
                    args.url,
                    playlist_fmt,
                    args.quality,
                    outdir,
                    reporter,
                    cookie_browsers=args.cookies_browser,
                    cookie_browser_profile=args.cookies_browser_profile,
                    cookie_file=args.cookies_file,
                ))

    except Exception as e:
        if "reporter" in locals():
            reporter.fail(str(e))
        fail(str(e))


if __name__ == "__main__":
    main()
